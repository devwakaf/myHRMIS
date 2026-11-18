<?php

declare(strict_types=1);

namespace App\Domain\Leave\Adapters;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Support\WorkedDay;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;

/**
 * M2 binding until Attendance (M3) exists: no-ops, no conflicts, and worked days
 * are reported as unverified so Cuti Gantian requests are confirmed by the approver.
 */
final class NullLeaveAttendancePort implements LeaveAttendancePort
{
    public function markLeave(LeaveApplication $application): void {}

    public function unmarkLeave(LeaveApplication $application): void {}

    public function conflicts(Employee $employee, DateRange $range): array
    {
        return [];
    }

    public function workedOn(Employee $employee, CarbonImmutable $date): WorkedDay
    {
        return WorkedDay::unverified($date);
    }
}
