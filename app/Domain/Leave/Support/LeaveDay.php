<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

/**
 * One approved leave day as seen by Attendance.
 */
final class LeaveDay
{
    public function __construct(
        public readonly string $date,
        public readonly int $leaveApplicationId,
        public readonly int $leaveTypeId,
        public readonly string $session,
        public readonly string $portion,
        public readonly string $unpaidPortion,
    ) {}
}
