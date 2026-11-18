<?php

declare(strict_types=1);

namespace App\Domain\Core\Reports;

use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Core\Services\EmployeeEligibility;
use App\Domain\Core\Services\EmployeeQuery;
use App\Domain\Core\Services\EmployeeSnapshot;
use App\Domain\Shared\Support\MalaysianIc;
use Carbon\CarbonImmutable;

/**
 * B5.1 Maklumat Kakitangan: one row per employee, list filters plus "as at" date
 * (assignment columns from work history); sensitive columns only with view-sensitive.
 */
final class EmployeeInfoReport
{
    public const COLUMNS = [
        'employee_no' => false, 'full_name' => false, 'status' => false, 'branch' => false, 'department' => false,
        'designation' => false, 'grade' => false, 'employment_type' => false, 'date_of_joining' => false,
        'service_start' => false, 'gender' => false, 'citizenship' => false, 'mobile_phone' => false, 'work_email' => false,
        'ic_no' => true, 'passport_no' => true, 'date_of_birth' => true, 'kwsp_no' => true, 'perkeso_no' => true,
        'bank_account_no' => true,
    ];

    public function __construct(private readonly EmployeeQuery $query) {}

    /**
     * @return list<string>
     */
    public function allowedColumns(bool $sensitive): array
    {
        return array_keys(array_filter(self::COLUMNS, fn (bool $isSensitive) => $sensitive || ! $isSensitive));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    public function rows(array $filters, array $columns, bool $sensitive): array
    {
        $asAt = empty($filters['as_at']) ? null : CarbonImmutable::parse((string) $filters['as_at']);
        $columns = array_values(array_intersect($columns, $this->allowedColumns($sensitive)));

        $assignmentFilters = ['branch_id', 'department_id', 'designation_id', 'grade_id', 'employment_type_id'];
        $baseFilters = $asAt === null ? $filters : array_diff_key($filters, array_flip(array_merge($assignmentFilters, ['status'])));

        $employees = $this->query->build($baseFilters, $sensitive)->orderBy('full_name')->get();
        $names = $this->names();
        $rows = [];

        foreach ($employees as $employee) {
            $snapshot = $asAt === null ? null : EmployeeSnapshot::asAt($employee, $asAt);
            if ($asAt !== null) {
                if (! EmployeeEligibility::isActiveOn($employee, $asAt)) {
                    continue;
                }
                foreach ($assignmentFilters as $f) {
                    $prop = lcfirst(str_replace('_', '', ucwords($f, '_')));
                    if (! empty($filters[$f]) && (int) $filters[$f] !== (int) $snapshot->{$prop}) {
                        continue 2;
                    }
                }
            }

            $values = [
                'employee_no' => $employee->employee_no,
                'full_name' => $employee->full_name,
                'status' => $snapshot->status ?? $employee->status->value,
                'branch' => $names['branch'][$snapshot->branchId ?? $employee->branch_id] ?? null,
                'department' => $names['department'][$snapshot->departmentId ?? $employee->department_id] ?? null,
                'designation' => $names['designation'][$snapshot->designationId ?? $employee->designation_id] ?? null,
                'grade' => $names['grade'][$snapshot->gradeId ?? $employee->grade_id] ?? null,
                'employment_type' => $names['employment_type'][$snapshot->employmentTypeId ?? $employee->employment_type_id] ?? null,
                'date_of_joining' => $employee->date_of_joining->toDateString(),
                'service_start' => $employee->serviceStart()->toDateString(),
                'gender' => $employee->gender->label(),
                'citizenship' => $employee->citizenship_status->label(),
                'mobile_phone' => $employee->mobile_phone,
                'work_email' => $employee->work_email,
                'ic_no' => $sensitive ? $employee->ic_no : MalaysianIc::mask($employee->ic_no),
                'passport_no' => $employee->passport_no,
                'date_of_birth' => $employee->date_of_birth->toDateString(),
                'kwsp_no' => $employee->getAttribute('kwsp_no'),
                'perkeso_no' => $employee->getAttribute('perkeso_no'),
                'bank_account_no' => $employee->getAttribute('bank_account_no'),
            ];

            $rows[] = array_intersect_key($values, array_flip($columns)) + ['id' => $employee->id];
        }

        return $rows;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function names(): array
    {
        $map = fn (string $class) => $class::query()->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()])->all();

        return [
            'branch' => $map(Branch::class),
            'department' => $map(Department::class),
            'designation' => $map(Designation::class),
            'grade' => $map(Grade::class),
            'employment_type' => $map(EmploymentType::class),
        ];
    }
}
