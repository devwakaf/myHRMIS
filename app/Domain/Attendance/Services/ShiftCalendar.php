<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single shift-resolution service (spec-03 §4.1), used by check-in,
 * processing, absent marking, reports and requests. Open-ended assignments
 * (end_date null) cover every later date.
 */
final class ShiftCalendar
{
    /** @var array<int, ShiftType|null> */
    private array $shiftTypes = [];

    /**
     * The occurrence whose (adjusted) window contains $t, or null (off-shift).
     */
    public function occurrenceAt(Employee $employee, CarbonImmutable $t): ?ShiftOccurrence
    {
        $day = $t->startOfDay();
        $occurrences = [];
        foreach ([$day->subDay(), $day, $day->addDay()] as $date) {
            array_push($occurrences, ...$this->build($employee, $date));
        }

        $occurrences = $this->adjustWindows($occurrences);
        $matches = array_values(array_filter($occurrences, fn (ShiftOccurrence $o) => $o->contains($t)));

        if ($matches === []) {
            return null;
        }

        // Prefer a non-ambiguous match; several ambiguous matches → return the first flagged.
        foreach ($matches as $match) {
            if (! $match->ambiguous) {
                return $match;
            }
        }

        return $matches[0];
    }

    /**
     * Occurrences nominally starting on $date (steps 2–3 only; used for absent marking).
     *
     * @return list<ShiftOccurrence>
     */
    public function occurrencesOn(Employee $employee, CarbonInterface $date): array
    {
        return $this->build($employee, CarbonImmutable::parse($date->format('Y-m-d')));
    }

    public function occurrenceOn(Employee $employee, CarbonInterface $date): ?ShiftOccurrence
    {
        return $this->occurrencesOn($employee, $date)[0] ?? null;
    }

    /**
     * @return list<ShiftOccurrence>
     */
    private function build(Employee $employee, CarbonImmutable $date): array
    {
        $assignments = ShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->overlapping($date, $date)
            ->with('shiftType')
            ->orderBy('start_date')
            ->get();

        $out = [];
        foreach ($assignments as $assignment) {
            $out[] = ShiftOccurrence::build($assignment->shiftType, $assignment, $date);
        }

        if ($out === [] && $employee->default_shift_type_id !== null) {
            $shift = $this->shiftType((int) $employee->default_shift_type_id);
            if ($shift !== null) {
                $out[] = ShiftOccurrence::build($shift, null, $date);
            }
        }

        return $out;
    }

    /**
     * Step 4: nominal overlap → ambiguous; window overlap → cut at the midpoint of the gap.
     *
     * @param  list<ShiftOccurrence>  $occurrences
     * @return list<ShiftOccurrence>
     */
    private function adjustWindows(array $occurrences): array
    {
        usort($occurrences, fn (ShiftOccurrence $a, ShiftOccurrence $b) => $a->start <=> $b->start);

        for ($i = 0; $i < count($occurrences) - 1; $i++) {
            $a = $occurrences[$i];
            $b = $occurrences[$i + 1];

            if ($a->end->greaterThan($b->start)) {
                $a->ambiguous = true;
                $b->ambiguous = true;

                continue;
            }

            if ($a->windowEnd->greaterThan($b->windowStart)) {
                $gapMinutes = intdiv((int) $a->end->diffInSeconds($b->start), 60);
                $midpoint = $a->end->addMinutes(intdiv($gapMinutes, 2));
                $a->windowEnd = $midpoint;
                $b->windowStart = $midpoint;
            }
        }

        return $occurrences;
    }

    private function shiftType(int $id): ?ShiftType
    {
        if (! array_key_exists($id, $this->shiftTypes)) {
            $this->shiftTypes[$id] = ShiftType::query()->find($id);
        }

        return $this->shiftTypes[$id];
    }
}
