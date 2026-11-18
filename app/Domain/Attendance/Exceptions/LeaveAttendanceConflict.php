<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Exceptions;

/**
 * markLeave found a worked / WFH / Tugas Luar row on a leave date (spec-03 §5.1).
 * Thrown inside Leave's engine transaction, so the approval rolls back.
 */
final class LeaveAttendanceConflict extends AttendanceRuleViolation
{
    /**
     * @param  list<string>  $dates  d/m/Y
     */
    public static function forDates(array $dates): self
    {
        return new self('attendance.errors.leave_conflict', ['dates' => implode(', ', $dates)]);
    }
}
