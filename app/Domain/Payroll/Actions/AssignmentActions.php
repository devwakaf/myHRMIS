<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\AssignmentReason;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryAssignmentAmount;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Services\TaxYearCalculator;
use App\Domain\Shared\Dating\EffectiveDatedSeries;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan Struktur Gaji (spec-04 §2.4): draft with per-component overrides and
 * opening YTD; submit inserts into the employee's A9 series (predecessor closed
 * at D − 1); cancel re-opens the predecessor unless a submitted slip used it.
 */
final class AssignmentActions
{
    public function __construct(private readonly EffectiveDatedSeries $series) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(?SalaryAssignment $row, array $data): SalaryAssignment
    {
        if ($row !== null && ! $row->isDraft()) {
            throw new PayrollRuleViolation('payroll.errors.not_draft');
        }
        $employee = Employee::query()->find($data['employee_id'] ?? 0);
        $structure = SalaryStructure::query()->find($data['salary_structure_id'] ?? 0);
        $errors = [];
        if ($employee === null) {
            $errors['employee_id'] = (string) __('payroll.errors.invalid_employee');
        }
        if ($structure === null || ! $structure->is_active) {
            $errors['salary_structure_id'] = (string) __('payroll.errors.structure_inactive');
        }
        if (empty($data['effective_from'])) {
            $errors['effective_from'] = (string) __('payroll.errors.period_required');
        }
        if (! is_numeric($data['base_salary'] ?? null) || Num::isNegative((string) $data['base_salary'])) {
            $errors['base_salary'] = (string) __('payroll.errors.invalid_amount');
        }
        DomainValidation::throwIf($errors);
        /** @var Employee $employee */
        /** @var SalaryStructure $structure */
        $from = CarbonImmutable::parse((string) $data['effective_from']);
        if ($from->lessThan($employee->date_of_joining) || ($employee->relieving_date !== null && $from->greaterThan($employee->relieving_date))) {
            throw DomainValidation::withMessages(['effective_from' => (string) __('payroll.errors.assignment_outside_employment')]);
        }

        $amounts = (array) ($data['amounts'] ?? []);
        $allowed = $structure->rows()->pluck('salary_component_id')->all();
        foreach ($amounts as $componentId => $amount) {
            if (! in_array((int) $componentId, $allowed, true)) {
                throw DomainValidation::withMessages(['amounts' => (string) __('payroll.errors.override_not_in_structure')]);
            }
        }

        $values = [
            'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'effective_from' => $from->toDateString(),
            'base_salary' => Num::money((string) $data['base_salary']),
            'reason' => AssignmentReason::tryFrom((string) ($data['reason'] ?? 'new_hire')) ?? AssignmentReason::NewHire,
            'epf_employee_rate_override' => $data['epf_employee_rate_override'] ?? null,
            'epf_employer_rate_override' => $data['epf_employer_rate_override'] ?? null,
            'opening_year' => $data['opening_year'] ?? null,
            'opening_ea_breakdown' => $data['opening_ea_breakdown'] ?? null,
            'opening_source' => $data['opening_source'] ?? 'manual',
            'remarks' => $data['remarks'] ?? null,
        ];
        foreach (SalaryAssignment::OPENING_COLUMNS as $column) {
            $values[$column] = Num::money((string) ($data[$column] ?? '0'));
        }

        return DB::transaction(function () use ($row, $values, $amounts): SalaryAssignment {
            $row ??= new SalaryAssignment;
            $row->fill($values)->save();
            SalaryAssignmentAmount::query()->where('salary_assignment_id', $row->id)->delete();
            foreach ($amounts as $componentId => $amount) {
                if ($amount === null || $amount === '') {
                    continue;
                }
                SalaryAssignmentAmount::query()->create(['salary_assignment_id' => $row->id, 'salary_component_id' => (int) $componentId, 'amount' => Num::money((string) $amount)]);
            }

            return $row;
        });
    }

    public function submit(SalaryAssignment $row, ?User $actor): SalaryAssignment
    {
        return DB::transaction(function () use ($row, $actor): SalaryAssignment {
            $row->transitionTo(DocumentStatus::Submitted, $actor);
            $this->series->insert($row);

            return $row;
        });
    }

    public function cancel(SalaryAssignment $row, User $actor, string $reason): SalaryAssignment
    {
        if (SalarySlip::query()->where('salary_assignment_id', $row->id)->where('status', '!=', DocumentStatus::Cancelled->value)->exists()) {
            throw new PayrollRuleViolation('payroll.errors.assignment_used');
        }

        return DB::transaction(function () use ($row, $actor, $reason): SalaryAssignment {
            if ($row->isDraft()) {
                $row->delete();

                return $row;
            }
            $this->series->remove($row);
            $row->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

            return $row;
        });
    }

    /**
     * Rehire in the same Organisasi and year (spec-00 B2): sum the previous
     * record's submitted slips of that year into the opening columns for HR review.
     */
    public function prefillRehireOpening(SalaryAssignment $row): SalaryAssignment
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        if ($employee->previous_employee_id === null) {
            throw new PayrollRuleViolation('payroll.errors.no_previous_record');
        }
        $year = $row->effective_from->year;
        $totals = app(TaxYearCalculator::class)->totals($employee->previous_employee_id, $year);
        $row->fill([
            'opening_year' => $year,
            'opening_source' => 'rehire_prefill',
            'opening_gross_normal' => $totals->yNormal,
            'opening_gross_additional' => $totals->yAdditional,
            'opening_epf_employee' => $totals->k,
            'opening_pcb' => $totals->x,
            'opening_zakat' => $totals->z,
            'opening_cp38' => $totals->cp38,
            'opening_socso_employee' => $totals->socsoEmployee,
            'opening_eis_employee' => $totals->eisEmployee,
            'opening_skbbk_employee' => $totals->skbbkEmployee,
            'opening_lp' => $totals->lp,
            'opening_ea_breakdown' => $totals->eaBoxes,
        ])->save();

        return $row;
    }
}
