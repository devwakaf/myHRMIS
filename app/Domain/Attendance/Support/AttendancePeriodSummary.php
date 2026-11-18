<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

/**
 * Attendance facts for a pay period (spec-03 §5.2 + spec-04 §4.2.1 delta 2).
 * Day counts are decimal strings (scale 3). Invariant: Σ absentDates.fraction = absentDays.
 * onLeaveDays is informational only — LWP comes from Leave.
 */
final class AttendancePeriodSummary
{
    /**
     * @param  list<array{date: string, fraction: string}>  $absentDates
     * @param  list<string>  $unmarkedDates
     * @param  list<string>  $needsReviewDates
     * @param  list<string>  $restDayWorkedDates
     * @param  list<string>  $publicHolidayWorkedDates
     */
    public function __construct(
        public readonly string $absentDays,
        public readonly array $absentDates,
        public readonly int $unmarkedDays,
        public readonly array $unmarkedDates,
        public readonly array $needsReviewDates,
        public readonly string $presentDays,
        public readonly string $wfhDays,
        public readonly string $onDutyDays,
        public readonly string $onLeaveDays,
        public readonly array $restDayWorkedDates,
        public readonly array $publicHolidayWorkedDates,
        public readonly int $lateCount,
        public readonly int $lateMinutes,
        public readonly int $earlyExitCount,
        public readonly int $earlyExitMinutes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
