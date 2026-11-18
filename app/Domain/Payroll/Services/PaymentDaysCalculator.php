<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Core\Enums\DailyRateBasis;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\DailyRatePolicy;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Payroll\Exceptions\PayrollCalculationException;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Support\PaymentDays;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Support\DomainError;
use Carbon\CarbonImmutable;

/**
 * Payment days by the deduction model (spec-04 §4.3). Unpaid leave comes only
 * from Leave's UnpaidLeaveQuery; absences and unmarked days from Attendance only
 * when days_source = leave_and_attendance (spec-02 §5.2, spec-03 §5.2).
 */
final class PaymentDaysCalculator
{
    public function __construct(
        private readonly HolidayResolver $holidays,
        private readonly UnpaidLeaveQuery $unpaidLeave,
        private readonly AttendanceForPayroll $attendance,
    ) {}

    /**
     * @return array{days: PaymentDays, unpaid_items: list<array<string, mixed>>, absent_dates: list<array{date: string, fraction: string}>, unmarked_dates: list<string>, pending_unpaid: string, actual_start: CarbonImmutable, actual_end: CarbonImmutable}
     */
    public function compute(Employee $employee, Organisation $organisation, CarbonImmutable $periodStart, PayrollSettings $settings, ?string $lwpOverride = null): array
    {
        $periodStart = $periodStart->startOfMonth();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();
        $actualStart = $periodStart->max($employee->date_of_joining);
        $actualEnd = $employee->relieving_date === null ? $periodEnd : $periodEnd->min($employee->relieving_date);
        if ($actualEnd->lessThan($actualStart)) {
            throw PayrollCalculationException::of('not_employed_in_period');
        }

        $partial = $actualStart->greaterThan($periodStart) || $actualEnd->lessThan($periodEnd);
        $policy = DailyRatePolicy::for($organisation);
        $basis = $partial && $settings->partial_month_basis === 'calendar_days' ? DailyRateBasis::CalendarDays : $policy->basis;
        $calendarDays = (string) $periodStart->daysInMonth;

        $workingDays = $this->workingDays($employee, $periodStart, $periodEnd);
        $needsWorking = $basis === DailyRateBasis::WorkingDays || ($basis === DailyRateBasis::FixedDivisor && $partial);
        if ($needsWorking && $workingDays === null) {
            throw PayrollCalculationException::of('no_holiday_list');
        }

        [$divisor, $outside] = match ($basis) {
            DailyRateBasis::FixedDivisor => [$policy->divisor, $partial ? $this->outsideWorkingDays($employee, $periodStart, $periodEnd, $actualStart, $actualEnd) : '0'],
            DailyRateBasis::CalendarDays => [$calendarDays, (string) ($periodStart->daysInMonth - ((new DateRange($actualStart, $actualEnd))->days()))],
            DailyRateBasis::WorkingDays => [(string) $workingDays, $partial ? $this->outsideWorkingDays($employee, $periodStart, $periodEnd, $actualStart, $actualEnd) : '0'],
        };

        $span = new DateRange($actualStart, $actualEnd);
        $summary = $this->unpaidLeave->unpaidDays($employee, $span);
        $items = array_values(array_filter($summary->items, fn (array $i) => $span->contains($i['date'])));
        $lwp = '0';
        foreach ($items as $item) {
            $lwp = Num::add($lwp, $item['unpaid_portion']);
        }
        if ($lwpOverride !== null) {
            $lwp = $lwpOverride;
        }

        $absent = '0';
        $unmarked = '0';
        $absentDates = [];
        $unmarkedDates = [];
        if ($settings->days_source === 'leave_and_attendance') {
            $att = $this->attendance->periodSummary($employee, $periodStart, $periodEnd);
            $absent = $att->absentDays;
            $absentDates = $att->absentDates;
            $unmarked = (string) $att->unmarkedDays;
            $unmarkedDates = $att->unmarkedDates;
        }

        $days = new PaymentDays(
            $calendarDays, $workingDays, $basis->value, Num::fixed($divisor, 2), Num::fixed($outside, 3), Num::fixed($lwp, 3),
            Num::fixed($absent, 3), Num::fixed($unmarked, 3), $settings->unmarked_day_treatment === 'absent',
            $actualStart->greaterThan($periodStart), $actualEnd->lessThan($periodEnd),
        );

        return [
            'days' => $days,
            'unpaid_items' => $items,
            'absent_dates' => $absentDates,
            'unmarked_dates' => $unmarkedDates,
            'pending_unpaid' => $this->unpaidLeave->pendingUnpaidDays($employee, $span),
            'actual_start' => $actualStart,
            'actual_end' => $actualEnd,
        ];
    }

    public function workingDays(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        try {
            return $this->holidays->workingDaysBetween($employee, $from, $to);
        } catch (DomainError) {
            return null;
        }
    }

    /** Working days of the month outside [actualStart, actualEnd] (half-day holidays 0.5). */
    public function outsideWorkingDays(Employee $employee, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $actualStart, CarbonImmutable $actualEnd): string
    {
        $outside = '0';
        if ($actualStart->greaterThan($periodStart)) {
            $outside = Num::add($outside, $this->workingDays($employee, $periodStart, $actualStart->subDay()) ?? '0');
        }
        if ($actualEnd->lessThan($periodEnd)) {
            $outside = Num::add($outside, $this->workingDays($employee, $actualEnd->addDay(), $periodEnd) ?? '0');
        }

        return $outside;
    }
}
