<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeWorkHistory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Assignment and status of an employee on a date, from work history (B6).
 */
final class EmployeeSnapshot
{
    public function __construct(
        public readonly int $employeeId,
        public readonly ?int $branchId,
        public readonly ?int $departmentId,
        public readonly ?int $designationId,
        public readonly ?int $gradeId,
        public readonly ?int $employmentTypeId,
        public readonly ?int $reportsToId,
        public readonly string $status,
    ) {}

    public static function asAt(Employee $employee, CarbonInterface|string $date): self
    {
        $day = CarbonImmutable::parse($date instanceof CarbonInterface ? $date->format('Y-m-d') : $date)->startOfDay();

        $row = EmployeeWorkHistory::query()
            ->where('employee_id', $employee->id)
            ->on($day)
            ->orderByDesc('from_date')
            ->first();

        $status = match (true) {
            $day->lessThan($employee->date_of_joining) => 'not_joined',
            $employee->relieving_date !== null && $day->greaterThan($employee->relieving_date) => EmployeeStatus::Left->value,
            $employee->status === EmployeeStatus::Suspended => EmployeeStatus::Suspended->value,
            default => EmployeeStatus::Active->value,
        };

        if ($row === null) {
            // Before the first row or after it closed: fall back to the current record.
            return new self($employee->id, $employee->branch_id, $employee->department_id, $employee->designation_id,
                $employee->grade_id, $employee->employment_type_id, $employee->reports_to_id, $status);
        }

        return new self($employee->id, $row->branch_id, $row->department_id, $row->designation_id,
            $row->grade_id, $row->employment_type_id, $row->reports_to_id, $status);
    }
}
