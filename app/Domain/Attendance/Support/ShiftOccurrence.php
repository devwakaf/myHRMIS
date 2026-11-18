<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftType;
use Carbon\CarbonImmutable;

/**
 * One nominal occurrence of a shift on a date (spec-03 §4.1). Windows are
 * half-open [windowStart, windowEnd). `assignment` is null for the employee's
 * default shift (source `default`).
 */
final class ShiftOccurrence
{
    public function __construct(
        public readonly ShiftType $shift,
        public readonly ?ShiftAssignment $assignment,
        public readonly CarbonImmutable $date,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public CarbonImmutable $windowStart,
        public CarbonImmutable $windowEnd,
        public bool $ambiguous = false,
    ) {}

    public static function build(ShiftType $shift, ?ShiftAssignment $assignment, CarbonImmutable $date): self
    {
        $start = $date->setTimeFromTimeString((string) $shift->start_time);
        $end = $date->setTimeFromTimeString((string) $shift->end_time);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return new self(
            $shift,
            $assignment,
            $date->startOfDay(),
            $start,
            $end,
            $start->subMinutes((int) $shift->open_before_minutes),
            $end->addMinutes((int) $shift->close_after_minutes),
        );
    }

    public function source(): string
    {
        return $this->assignment === null ? 'default' : 'assignment';
    }

    public function contains(CarbonImmutable $t): bool
    {
        return $t->greaterThanOrEqualTo($this->windowStart) && $t->lessThan($this->windowEnd);
    }

    /**
     * Snapshot columns stored on a punch.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'shift_type_id' => $this->shift->id,
            'shift_assignment_id' => $this->assignment?->id,
            'shift_date' => $this->date->toDateString(),
            'is_ambiguous' => $this->ambiguous,
            'scheduled_start_at' => $this->start,
            'scheduled_end_at' => $this->end,
            'window_start_at' => $this->windowStart,
            'window_end_at' => $this->windowEnd,
        ];
    }

    /**
     * Snapshot columns used when no shift resolves (off-shift).
     *
     * @return array<string, mixed>
     */
    public static function emptySnapshot(): array
    {
        return [
            'shift_type_id' => null, 'shift_assignment_id' => null, 'shift_date' => null, 'is_ambiguous' => false,
            'scheduled_start_at' => null, 'scheduled_end_at' => null, 'window_start_at' => null, 'window_end_at' => null,
        ];
    }
}
