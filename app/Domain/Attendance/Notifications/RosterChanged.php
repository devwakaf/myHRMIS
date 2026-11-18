<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Notifications;

/** To an employee: their roster changed within the notice window (spec-03 §2.9). */
final class RosterChanged extends AttendanceNotice
{
    public function __construct(public readonly string $from, public readonly string $to) {}

    public function kind(): string
    {
        return 'roster_changed';
    }

    public function params(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }
}
