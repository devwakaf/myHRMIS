<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Notifications;

/** To an employee: shift started 30 min ago and no punch yet (optional job, spec-03 §6). */
final class MissingCheckinReminder extends AttendanceNotice
{
    public function __construct(public readonly string $shiftLabel) {}

    public function kind(): string
    {
        return 'missing_checkin';
    }

    public function params(): array
    {
        return ['shift' => $this->shiftLabel];
    }
}
