<?php

declare(strict_types=1);

namespace App\Domain\Leave\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Support\WorkedDay;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;

/**
 * Leave → Attendance (spec-02 §5.1). Owned by Leave, implemented by Attendance (M3).
 * M2 binds NullLeaveAttendancePort.
 */
interface LeaveAttendancePort
{
    /**
     * Create/replace attendance for each day with portion > 0 (or counts_holidays):
     * full → on_leave; am/pm half day → half_day with the other session expected present.
     * Hadir / Bekerja Dari Rumah rows are never overwritten. Idempotent.
     */
    public function markLeave(LeaveApplication $application): void;

    /** Cancel every attendance row linked to the application. */
    public function unmarkLeave(LeaveApplication $application): void;

    /**
     * Dates in range already marked Hadir / Bekerja Dari Rumah.
     *
     * @return list<array{date: string, session: string}> session: full|am|pm (the part that was worked)
     */
    public function conflicts(Employee $employee, DateRange $range): array;

    /** Whether the employee worked on a date (Cuti Gantian); null = did not work. */
    public function workedOn(Employee $employee, CarbonImmutable $date): ?WorkedDay;
}
