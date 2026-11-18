<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Enums\ReviewReason;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Re-resolution of unprocessed punches after assignments / rosters / shift times
 * change (spec-03 §4.1): punches `pending` or in review for off_shift /
 * ambiguous_shift get a fresh snapshot. Processed punches are never touched.
 */
final class CheckinResolver
{
    public function __construct(private readonly ShiftCalendar $calendar) {}

    public function reresolveEmployee(Employee $employee, CarbonImmutable $from, ?CarbonImmutable $to): int
    {
        $query = EmployeeCheckin::query()->notVoided()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $from->subDay()->startOfDay())
            ->where(fn ($q) => $q->where('processing_state', ProcessingState::Pending->value)
                ->orWhere(fn ($q) => $q->where('processing_state', ProcessingState::NeedsReview->value)
                    ->whereIn('review_reason', [ReviewReason::OffShift->value, ReviewReason::AmbiguousShift->value])));
        if ($to !== null) {
            $query->where('punched_at', '<', $to->addDays(2)->startOfDay());
        }

        $count = 0;
        foreach ($query->get() as $punch) {
            $this->resolve($employee, $punch);
            $count++;
        }

        return $count;
    }

    /** Fresh snapshot; the punch goes back to `pending`. */
    public function resolve(Employee $employee, EmployeeCheckin $punch): void
    {
        $occurrence = $this->calendar->occurrenceAt($employee, $punch->punched_at);
        $punch->forceFill(array_merge(
            $occurrence?->snapshot() ?? ShiftOccurrence::emptySnapshot(),
            ['processing_state' => ProcessingState::Pending, 'review_reason' => null, 'attendance_id' => null],
        ))->save();
    }
}
