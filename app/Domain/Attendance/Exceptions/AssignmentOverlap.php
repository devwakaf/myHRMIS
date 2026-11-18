<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Exceptions;

use App\Domain\Attendance\Models\ShiftAssignment;

/** A new Penugasan Shift would share dates with an existing one (spec-03 §3.4). */
final class AssignmentOverlap extends AttendanceRuleViolation
{
    /** @var list<int> */
    public array $conflictIds = [];

    /**
     * @param  iterable<ShiftAssignment>  $conflicts
     */
    public static function with(iterable $conflicts): self
    {
        $ids = [];
        $labels = [];
        foreach ($conflicts as $c) {
            $ids[] = $c->id;
            $labels[] = $c->start_date->format('d/m/Y').'–'.($c->end_date?->format('d/m/Y') ?? '∞');
        }
        $e = new self('attendance.errors.assignment_overlap', ['ranges' => implode(', ', $labels)]);
        $e->conflictIds = $ids;

        return $e;
    }
}
