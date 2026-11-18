<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Employees a run covers (spec-04 §5.1): same Organisasi, active or suspended
 * (left only if relieved on/after period start), joined by period end, matching
 * the run filters. Those without a submitted assignment are listed separately.
 */
final class EligibleEmployees
{
    /**
     * @return array{eligible: Collection<int, Employee>, no_assignment: Collection<int, Employee>, filtered_out: int}
     */
    public function for(PayrollRun $run): array
    {
        $base = $this->baseQuery($run);
        $all = (clone $base)->orderBy('employee_no')->get();
        $filtered = $this->applyFilters(clone $base, $run->filters ?? [])->orderBy('employee_no')->get();

        $withAssignment = SalaryAssignment::query()->where('status', DocumentStatus::Submitted->value)
            ->whereDate('effective_from', '<=', $run->period_end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $run->period_start->toDateString()))
            ->whereIn('employee_id', $filtered->pluck('id'))
            ->pluck('employee_id')->unique()->all();

        return [
            'eligible' => $filtered->filter(fn (Employee $e) => in_array($e->id, $withAssignment, true))->values(),
            'no_assignment' => $filtered->reject(fn (Employee $e) => in_array($e->id, $withAssignment, true))->values(),
            'filtered_out' => $all->count() - $filtered->count(),
        ];
    }

    /**
     * @return Builder<Employee>
     */
    private function baseQuery(PayrollRun $run): Builder
    {
        return Employee::query()
            ->whereDate('date_of_joining', '<=', $run->period_end->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereIn('status', [EmployeeStatus::Active->value, EmployeeStatus::Suspended->value])
                ->orWhere(fn (Builder $left) => $left->where('status', EmployeeStatus::Left->value)
                    ->whereDate('relieving_date', '>=', $run->period_start->toDateString())))
            ->where(fn (Builder $q) => $q->whereNull('relieving_date')->orWhereDate('relieving_date', '>=', $run->period_start->toDateString()));
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['branch_id', 'department_id', 'grade_id', 'employment_type_id'] as $column) {
            $values = array_values(array_filter((array) ($filters[$column] ?? []), fn ($v) => $v !== null && $v !== ''));
            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        return $query;
    }
}
