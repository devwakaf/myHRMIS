<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeWorkHistory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Keeps employee_work_history consistent: one open row per active employee (B1.12).
 */
final class WorkHistoryRecorder
{
    public function open(Employee $employee, CarbonInterface $from, WorkHistorySource $source, ?int $sourceId = null): EmployeeWorkHistory
    {
        return EmployeeWorkHistory::query()->create([
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'from_date' => $from->format('Y-m-d'),
            'to_date' => null,
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'designation_id' => $employee->designation_id,
            'grade_id' => $employee->grade_id,
            'employment_type_id' => $employee->employment_type_id,
            'reports_to_id' => $employee->reports_to_id,
            'source_type' => $source,
            'source_id' => $sourceId,
        ]);
    }

    public function current(Employee $employee): ?EmployeeWorkHistory
    {
        return EmployeeWorkHistory::query()
            ->where('employee_id', $employee->id)
            ->whereNull('to_date')
            ->orderByDesc('from_date')
            ->first();
    }

    /**
     * Close the open row at $from − 1 and open a new one from $from with the
     * employee's current assignment values.
     */
    public function transition(Employee $employee, CarbonInterface $from, WorkHistorySource $source, ?int $sourceId = null): EmployeeWorkHistory
    {
        $current = $this->current($employee);
        $from = CarbonImmutable::parse($from->format('Y-m-d'));

        if ($current !== null) {
            if ($current->from_date->greaterThanOrEqualTo($from)) {
                // Same-day change: the earlier row would be empty, so drop it.
                $current->delete();
            } else {
                $current->update(['to_date' => $from->subDay()->toDateString()]);
            }
        }

        return $this->open($employee, $from, $source, $sourceId);
    }

    /** Close the open row (separation). */
    public function close(Employee $employee, CarbonInterface $to): void
    {
        $this->current($employee)?->update(['to_date' => $to->format('Y-m-d')]);
    }

    /** Update the open row in place (Pembetulan, B3.6). */
    public function correctCurrent(Employee $employee): void
    {
        $current = $this->current($employee);
        if ($current === null) {
            $this->open($employee, $employee->date_of_joining, WorkHistorySource::Correction);

            return;
        }

        $current->update([
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'designation_id' => $employee->designation_id,
            'grade_id' => $employee->grade_id,
            'employment_type_id' => $employee->employment_type_id,
            'reports_to_id' => $employee->reports_to_id,
        ]);
    }
}
