<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Support\AttendancePeriodSummary;
use App\Domain\Attendance\Support\EmployeeCompleteness;
use App\Domain\Attendance\Support\OvertimePayLine;
use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Attendance → Payroll (spec-03 §5.2 with spec-04 §4.2.1 deltas 2, 3, 6).
 * Implemented by Attendance (AttendancePayrollQueries), consumed by Payroll (M4).
 */
interface AttendanceForPayroll
{
    public function periodSummary(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): AttendancePeriodSummary;

    /**
     * Employees with unmarked or needs-review days.
     *
     * @param  list<int>|null  $employeeIds
     * @return list<EmployeeCompleteness>
     */
    public function completeness(int $organisationId, CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds = null): array;

    /**
     * Approved, unposted claims whose month is on or before $periodMonth, grouped
     * by (claim, salary component).
     *
     * @return list<OvertimePayLine>
     */
    public function overtimePayLines(Employee $employee, CarbonImmutable $periodMonth): array;

    /**
     * @param  list<int>  $claimIds
     */
    public function markOvertimePosted(array $claimIds, int $payrollRunId): void;

    public function releaseOvertime(int $payrollRunId): void;
}
