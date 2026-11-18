<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Exceptions\HolidayListDoesNotCover;
use App\Domain\Core\Exceptions\NoHolidayListAssigned;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Leave\Enums\DayKind;
use App\Domain\Leave\Enums\DaySession;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\DayBreakdown;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Support\DomainValidation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Day counting (spec-02 §3.1): one row per calendar date with its kind, the
 * deducted portion (0 / 0.5 / 1) and the unpaid portion. Rest days and holidays
 * come from HolidayResolver, split per governing list.
 */
final class LeaveDayCalculator
{
    public function __construct(private readonly HolidayResolver $holidays) {}

    public function build(
        Employee $employee,
        LeaveType $type,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        CarbonInterface|string|null $halfDayDate = null,
        ?DaySession $session = null,
    ): DayBreakdown {
        $from = DateRange::day($from);
        $to = DateRange::day($to);
        $half = $halfDayDate === null ? null : DateRange::day($halfDayDate);

        if ($to->lessThan($from)) {
            throw DomainValidation::withMessages(['to_date' => __('leave.errors.to_before_from')]);
        }
        if ($from->lessThan($employee->date_of_joining)) {
            throw DomainValidation::withMessages(['from_date' => __('leave.errors.before_joining', ['date' => $employee->date_of_joining->format('d/m/Y')])]);
        }
        if ($employee->relieving_date !== null && $to->greaterThan($employee->relieving_date)) {
            throw DomainValidation::withMessages(['to_date' => __('leave.errors.after_relieving', ['date' => $employee->relieving_date->format('d/m/Y')])]);
        }

        try {
            $segments = $this->holidays->segments($employee, $from, $to);
            $holidayRows = $this->holidays->holidaysBetween($employee, $from, $to);
        } catch (NoHolidayListAssigned|HolidayListDoesNotCover $e) {
            throw DomainValidation::withMessages(['from_date' => $e->userMessage()]);
        }

        $listFor = function (CarbonImmutable $d) use ($segments): ?int {
            foreach ($segments as $segment) {
                if ($d->betweenIncluded($segment['from'], $segment['to'])) {
                    return $segment['list']->id;
                }
            }

            return null;
        };

        $unpaidFraction = $type->unpaidFraction();
        $days = [];
        $total = '0';
        $unpaid = '0';

        foreach ((new DateRange($from, $to))->dates() as $date) {
            $holiday = $holidayRows->get($date->toDateString());
            $kind = DayKind::Working;
            if ($holiday !== null) {
                $kind = match (true) {
                    $holiday['isHalfDay'] => DayKind::HalfWorking,
                    $holiday['kind'] === HolidayKind::WeeklyOff->value => DayKind::WeeklyOff,
                    default => DayKind::Holiday,
                };
            }

            $portion = match ($kind) {
                DayKind::Working => '1',
                DayKind::HalfWorking => $type->counts_holidays ? '1' : '0.5',
                DayKind::WeeklyOff, DayKind::Holiday => $type->counts_holidays ? '1' : '0',
            };
            $daySession = DaySession::Full;

            if ($half !== null && $half->equalTo($date)) {
                if (! $type->allow_half_day) {
                    throw DomainValidation::withMessages(['half_day_date' => __('leave.errors.half_day_not_allowed')]);
                }
                if ($kind !== DayKind::Working) {
                    throw DomainValidation::withMessages(['half_day_date' => __('leave.errors.half_day_not_working')]);
                }
                $portion = '0.5';
                $daySession = $session ?? DaySession::Am;
            }

            $portion = Dec::norm($portion);
            $unpaidPortion = Dec::mul($portion, $unpaidFraction);
            $total = Dec::add($total, $portion);
            $unpaid = Dec::add($unpaid, $unpaidPortion);

            $days[] = [
                'date' => $date,
                'day_kind' => $kind,
                'session' => $daySession,
                'portion' => $portion,
                'unpaid_portion' => $unpaidPortion,
                'holiday_list_id' => $holiday['listId'] ?? $listFor($date),
                'holiday_name' => $holiday['name'] ?? null,
            ];
        }

        if ($half !== null && ! $half->equalTo($from) && ! $half->equalTo($to)) {
            throw DomainValidation::withMessages(['half_day_date' => __('leave.errors.half_day_edge_only')]);
        }

        if (! Dec::isPositive($total)) {
            throw DomainValidation::withMessages(['from_date' => __('leave.errors.no_working_days')]);
        }

        return new DayBreakdown($days, $total, $unpaid, count($days));
    }

    /** Bersalin-style types: to_date = from + per_event_max_days − 1 (§3.1). */
    public function suggestedToDate(LeaveType $type, CarbonInterface|string $from): ?CarbonImmutable
    {
        if (! $type->counts_holidays || $type->per_event_max_days === null) {
            return null;
        }

        $days = (int) Dec::short($type->per_event_max_days);

        return DateRange::day($from)->addDays(max(1, $days) - 1);
    }
}
