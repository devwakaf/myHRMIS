<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Adapters;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Carbon\CarbonImmutable;

/**
 * The canonical period lock (spec-04 §4.2): a date is locked for an employee
 * when a submitted Slip Gaji (run submitted or paid) covers its month. Draft and
 * generated runs never lock. Queries bypass the organisation scope because Leave,
 * Attendance and Core call it from their own contexts (employee id is unique).
 */
final class PayrollLockAdapter implements PayrollLockQuery
{
    public function isLocked(Employee|int $employee, CarbonImmutable $date): bool
    {
        $day = DateRange::day($date)->toDateString();

        return SalarySlip::acrossOrganisations()
            ->where('employee_id', $employee instanceof Employee ? $employee->id : $employee)
            ->where('status', DocumentStatus::Submitted->value)
            ->whereDate('period_start', '<=', $day)
            ->whereDate('period_end', '>=', $day)
            ->exists();
    }

    public function lockedDatesIn(Employee|int $employee, DateRange $range): array
    {
        $slips = SalarySlip::acrossOrganisations()
            ->where('employee_id', $employee instanceof Employee ? $employee->id : $employee)
            ->where('status', DocumentStatus::Submitted->value)
            ->whereDate('period_end', '>=', $range->from->toDateString())
            ->when($range->to !== null, fn ($q) => $q->whereDate('period_start', '<=', $range->to?->toDateString()))
            ->orderBy('period_start')
            ->get(['period_start', 'period_end']);

        $dates = [];
        foreach ($slips as $slip) {
            $overlap = (new DateRange($slip->period_start, $slip->period_end))->intersect($range);
            if ($overlap === null) {
                continue;
            }
            foreach ($overlap->dates() as $d) {
                $dates[$d->toDateString()] = $d;
            }
        }
        ksort($dates);

        return array_values($dates);
    }
}
