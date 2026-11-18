<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Support\MalaysianIc;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search and filters shared by Senarai Kakitangan, its export and the
 * Maklumat Kakitangan report (B4, B5).
 */
final class EmployeeQuery
{
    public const FILTERS = ['search', 'status', 'branch_id', 'department_id', 'designation_id', 'grade_id', 'employment_type_id', 'citizenship_status'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    public function build(array $filters, bool $canSearchSensitive): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Employee::query()
            ->with(['branch', 'department', 'designation', 'grade', 'employmentType'])
            ->when($search !== '', function (Builder $q) use ($search, $canSearchSensitive): void {
                $q->where(function (Builder $w) use ($search, $canSearchSensitive): void {
                    $w->where('full_name', 'like', "%{$search}%")
                        ->orWhere('preferred_name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                    if ($canSearchSensitive) {
                        $digits = MalaysianIc::normalise($search);
                        $w->orWhere('ic_no', 'like', "%{$digits}%")->orWhere('passport_no', 'like', "%{$search}%");
                    }
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $v) => $q->where('department_id', $v))
            ->when($filters['designation_id'] ?? null, fn (Builder $q, $v) => $q->where('designation_id', $v))
            ->when($filters['grade_id'] ?? null, fn (Builder $q, $v) => $q->where('grade_id', $v))
            ->when($filters['employment_type_id'] ?? null, fn (Builder $q, $v) => $q->where('employment_type_id', $v))
            ->when($filters['citizenship_status'] ?? null, fn (Builder $q, $v) => $q->where('citizenship_status', $v));
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(Employee $employee, bool $sensitive): array
    {
        return [
            'id' => $employee->id,
            'employee_no' => $employee->employee_no,
            'full_name' => $employee->full_name,
            'ic_no' => $sensitive ? $employee->ic_no : MalaysianIc::mask($employee->ic_no),
            'passport_no' => $sensitive ? $employee->passport_no : null,
            'status' => $employee->status->value,
            'status_label' => $employee->status->label(),
            'branch' => $employee->branch?->displayName(),
            'department' => $employee->department?->displayName(),
            'designation' => $employee->designation?->displayName(),
            'grade' => $employee->grade?->displayName(),
            'employment_type' => $employee->employmentType?->displayName(),
            'citizenship' => $employee->citizenship_status->label(),
            'date_of_joining' => $employee->date_of_joining->toDateString(),
            'mobile_phone' => $employee->mobile_phone,
            'work_email' => $employee->work_email,
        ];
    }
}
