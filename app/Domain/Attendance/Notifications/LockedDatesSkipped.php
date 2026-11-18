<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Notifications;

/** To HR: leave sync skipped dates inside a locked payroll period (spec-03 §5.1). */
final class LockedDatesSkipped extends AttendanceNotice
{
    /**
     * @param  list<string>  $dates  d/m/Y
     */
    public function __construct(
        public readonly string $employeeName,
        public readonly string $reference,
        public readonly array $dates,
    ) {}

    public function kind(): string
    {
        return 'locked_dates_skipped';
    }

    public function params(): array
    {
        return ['employee' => $this->employeeName, 'reference' => $this->reference, 'dates' => implode(', ', $this->dates)];
    }
}
