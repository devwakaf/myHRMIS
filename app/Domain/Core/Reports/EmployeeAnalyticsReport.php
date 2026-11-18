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
use App\Domain\Core\Services\EmployeeSnapshot;
use Carbon\CarbonImmutable;

/**
 * B5.2 Analitik Kakitangan: headcount as at a date grouped by a dimension (from
 * work history, not current values), plus joiners/leavers in a range and
 * turnover % = leavers ÷ average headcount (start and end of the range).
 */
final class EmployeeAnalyticsReport
{
    public const DIMENSIONS = ['branch', 'department', 'designation', 'grade', 'employment_type', 'gender', 'citizenship', 'age_band', 'tenure_band'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $asAt = CarbonImmutable::parse((string) ($filters['as_at'] ?? today()->toDateString()));
        $dimension = in_array($filters['dimension'] ?? null, self::DIMENSIONS, true) ? (string) $filters['dimension'] : 'department';
        $from = CarbonImmutable::parse((string) ($filters['from'] ?? $asAt->startOfYear()->toDateString()));
        $to = CarbonImmutable::parse((string) ($filters['to'] ?? $asAt->toDateString()));

        $employees = Employee::query()->get();
        $groups = [];
        $total = 0;

        foreach ($employees as $employee) {
            if (! EmployeeEligibility::isActiveOn($employee, $asAt)) {
                continue;
            }
            $key = $this->groupKey($employee, $dimension, $asAt);
            $groups[$key] = ($groups[$key] ?? 0) + 1;
            $total++;
        }

        arsort($groups);
        $rows = [];
        foreach ($groups as $label => $count) {
            $rows[] = [
                'label' => $label,
                'count' => $count,
                'percent' => $total === 0 ? '0.0' : number_format($count / $total * 100, 1, '.', ''),
            ];
        }

        $joiners = $employees->filter(fn (Employee $e) => $e->date_of_joining->betweenIncluded($from, $to))->count();
        $leavers = $employees->filter(fn (Employee $e) => $e->relieving_date !== null && $e->relieving_date->betweenIncluded($from, $to))->count();
        $startCount = $employees->filter(fn (Employee $e) => EmployeeEligibility::isActiveOn($e, $from))->count();
        $endCount = $employees->filter(fn (Employee $e) => EmployeeEligibility::isActiveOn($e, $to))->count();
        $average = ($startCount + $endCount) / 2;

        return [
            'as_at' => $asAt->toDateString(),
            'dimension' => $dimension,
            'total' => $total,
            'rows' => $rows,
            'movement' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'joiners' => $joiners,
                'leavers' => $leavers,
                'average_headcount' => number_format($average, 1, '.', ''),
                'turnover_percent' => $average == 0 ? '0.0' : number_format($leavers / $average * 100, 1, '.', ''),
            ],
        ];
    }

    private function groupKey(Employee $employee, string $dimension, CarbonImmutable $asAt): string
    {
        $snapshot = EmployeeSnapshot::asAt($employee, $asAt);
        $none = (string) __('common.none');

        return match ($dimension) {
            'branch' => $snapshot->branchId === null ? $none : (Branch::query()->find($snapshot->branchId)?->displayName() ?? $none),
            'department' => Department::query()->find($snapshot->departmentId)?->displayName() ?? $none,
            'designation' => Designation::query()->find($snapshot->designationId)?->displayName() ?? $none,
            'grade' => $snapshot->gradeId === null ? $none : (Grade::query()->find($snapshot->gradeId)?->displayName() ?? $none),
            'employment_type' => EmploymentType::query()->find($snapshot->employmentTypeId)?->displayName() ?? $none,
            'gender' => $employee->gender->label(),
            'citizenship' => $employee->citizenship_status->label(),
            'age_band' => $this->ageBand((int) $employee->date_of_birth->diffInYears($asAt)),
            default => $this->tenureBand((int) $employee->serviceStart()->diffInYears($asAt)), // tenure_band
        };
    }

    private function ageBand(int $age): string
    {
        return match (true) {
            $age < 20 => '< 20',
            $age < 30 => '20–29',
            $age < 40 => '30–39',
            $age < 50 => '40–49',
            $age < 60 => '50–59',
            default => '60+',
        };
    }

    private function tenureBand(int $years): string
    {
        return match (true) {
            $years < 1 => (string) __('core.reports.tenure.lt1'),
            $years < 3 => (string) __('core.reports.tenure.1to2'),
            $years < 6 => (string) __('core.reports.tenure.3to5'),
            $years < 11 => (string) __('core.reports.tenure.6to10'),
            default => (string) __('core.reports.tenure.gt10'),
        };
    }
}
