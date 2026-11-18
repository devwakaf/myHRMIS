<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\AccrualFrequency;
use App\Domain\Leave\Enums\AccrualTiming;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicyRow;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;

/**
 * Earned-leave accrual schedule (spec-02 §3.8). Sub-periods are aligned to the
 * leave period start; each one contributes tier_days ÷ periods-per-year × covered
 * share. Rounding is cumulative (posted_k = toStep(cum_k) − Σ posted before), so
 * slices never drift.
 */
final class AccrualScheduleBuilder
{
    /**
     * @return list<array{due_date: CarbonImmutable, days: string}>
     */
    public function build(Employee $employee, LeaveType $type, LeavePolicyRow $row, LeavePeriod $period, DateRange $window): array
    {
        $frequency = $type->accrual_frequency ?? AccrualFrequency::Monthly;
        $timing = $type->accrual_timing ?? AccrualTiming::SubPeriodEnd;
        $row->loadMissing('tiers');
        $serviceStart = $employee->serviceStart();
        $windowTo = $window->to ?? $period->to_date;

        $rows = [];
        $cumulative = '0';
        $posted = '0';
        $start = $period->from_date;

        while ($start->lessThanOrEqualTo($period->to_date)) {
            $end = $start->addMonthsNoOverflow($frequency->months())->subDay()->min($period->to_date);
            $sub = new DateRange($start, $end);
            $covered = $sub->intersect(new DateRange($window->from, $windowTo));

            if ($covered !== null) {
                $tierDays = $row->daysForServiceMonths(EntitlementCalculator::serviceMonthsOn($serviceStart, $covered->from));
                $share = bcdiv((string) $covered->days(), (string) $sub->days(), 12);
                $value = bcdiv(bcmul($tierDays, $share, 12), (string) $frequency->periodsPerYear(), 12);
                $cumulative = bcadd($cumulative, $value, 12);

                $target = Dec::toStep($cumulative, $type->rounding_step === null ? null : (string) $type->rounding_step);
                if ($type->max_days_per_period !== null) {
                    $target = Dec::min($target, (string) $type->max_days_per_period);
                }
                $days = Dec::sub($target, $posted);

                if (Dec::isPositive($days)) {
                    $rows[] = ['due_date' => $this->dueDate($timing, $sub, $covered, $employee), 'days' => $days];
                    $posted = Dec::add($posted, $days);
                }
            }

            $start = $start->addMonthsNoOverflow($frequency->months());
        }

        return $rows;
    }

    private function dueDate(AccrualTiming $timing, DateRange $sub, DateRange $covered, Employee $employee): CarbonImmutable
    {
        $subEnd = $sub->to ?? $sub->from;

        return match ($timing) {
            AccrualTiming::SubPeriodStart => $covered->from,
            AccrualTiming::SubPeriodEnd => $subEnd,
            AccrualTiming::JoiningDay => (function () use ($subEnd, $covered, $employee): CarbonImmutable {
                $day = min($employee->date_of_joining->day, $subEnd->daysInMonth);
                $due = $subEnd->setDay($day);

                return $due->lessThan($covered->from) ? $covered->from : $due;
            })(),
        };
    }
}
