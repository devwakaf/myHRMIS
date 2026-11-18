<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\AdditionalKind;
use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\StatutoryKey;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Elaun/Potongan Tambahan (spec-04 §2.6): draft → submit → cancel with the
 * replace/overlap rules; system postings (OT, encashment, arrear, correction)
 * are created submitted and may only be cancelled through their source.
 */
final class AdditionalSalaryActions
{
    /** Sources a user may cancel directly. */
    private const USER_SOURCES = [AdditionalSource::Manual, AdditionalSource::Import];

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(?AdditionalSalary $row, array $data): AdditionalSalary
    {
        if ($row !== null && ! $row->isDraft()) {
            throw new PayrollRuleViolation('payroll.errors.not_draft');
        }

        $employee = Employee::query()->find($data['employee_id'] ?? 0);
        $component = SalaryComponent::query()->find($data['salary_component_id'] ?? 0);
        $kind = AdditionalKind::tryFrom((string) ($data['kind'] ?? 'one_off')) ?? AdditionalKind::OneOff;
        $errors = [];

        if ($employee === null) {
            $errors['employee_id'] = __('payroll.errors.invalid_employee');
        }
        if ($component === null || ! $component->is_active || ! self::componentAllowed($component)) {
            $errors['salary_component_id'] = __('payroll.errors.component_not_allowed');
        }
        if (! is_numeric($data['amount'] ?? null) || ! Num::isPositive((string) $data['amount'])) {
            $errors['amount'] = __('payroll.errors.amount_positive');
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            $errors['reason'] = __('payroll.errors.reason_required');
        }

        $values = [
            'kind' => $kind,
            'amount' => is_numeric($data['amount'] ?? null) ? Num::money((string) $data['amount']) : '0',
            'mode' => AdditionalMode::tryFrom((string) ($data['mode'] ?? 'add')) ?? AdditionalMode::Add,
            'prorate' => (bool) ($data['prorate'] ?? false),
            'reason' => (string) ($data['reason'] ?? ''),
            'note' => $data['note'] ?? null,
            'period' => null, 'from_period' => null, 'to_period' => null,
        ];

        if ($kind === AdditionalKind::OneOff) {
            if (empty($data['period'])) {
                $errors['period'] = __('payroll.errors.period_required');
            } else {
                $values['period'] = CarbonImmutable::parse((string) $data['period'])->startOfMonth()->toDateString();
            }
        } else {
            if (empty($data['from_period'])) {
                $errors['from_period'] = __('payroll.errors.period_required');
            } else {
                $from = CarbonImmutable::parse((string) $data['from_period'])->startOfMonth();
                $values['from_period'] = $from->toDateString();
                if (! empty($data['to_period'])) {
                    $to = CarbonImmutable::parse((string) $data['to_period'])->startOfMonth();
                    if ($to->lessThan($from)) {
                        $errors['to_period'] = __('payroll.errors.to_before_from');
                    } elseif ($from->diffInMonths($to) + 1 > AdditionalSalary::MAX_RECURRING_MONTHS) {
                        $errors['to_period'] = __('payroll.errors.recurring_too_long', ['max' => AdditionalSalary::MAX_RECURRING_MONTHS]);
                    }
                    $values['to_period'] = $to->toDateString();
                }
            }
        }

        $start = $values['period'] ?? $values['from_period'];
        if ($employee !== null && $start !== null) {
            $first = CarbonImmutable::parse($start);
            if ($employee->date_of_joining->greaterThan($first->endOfMonth())
                || ($employee->relieving_date !== null && $employee->relieving_date->lessThan($first))) {
                $errors['employee_id'] = __('payroll.errors.employee_not_active_in_period');
            }
        }
        DomainValidation::throwIf($errors);

        /** @var Employee $employee */
        /** @var SalaryComponent $component */
        $values['employee_id'] = $employee->id;
        $values['salary_component_id'] = $component->id;

        if ($row === null) {
            $row = new AdditionalSalary($values + ['source_type' => AdditionalSource::tryFrom((string) ($data['source_type'] ?? 'manual')) ?? AdditionalSource::Manual]);
            $row->save();
        } else {
            $row->fill($values)->save();
        }

        return $row;
    }

    public function submit(AdditionalSalary $row, ?User $actor): AdditionalSalary
    {
        return DB::transaction(function () use ($row, $actor): AdditionalSalary {
            $this->assertSubmittable($row);
            $row->transitionTo(DocumentStatus::Submitted, $actor);

            return $row;
        });
    }

    public function cancel(AdditionalSalary $row, ?User $actor, string $reason, bool $viaSource = false): AdditionalSalary
    {
        if (! $viaSource && ! in_array($row->source_type, self::USER_SOURCES, true)) {
            throw new PayrollRuleViolation('payroll.errors.cancel_via_source');
        }
        if ($row->applications()->whereHas('slip', fn ($q) => $q->where('status', DocumentStatus::Submitted->value))->exists()) {
            throw new PayrollRuleViolation('payroll.errors.additional_consumed');
        }

        return DB::transaction(function () use ($row, $actor, $reason): AdditionalSalary {
            $row->applications()->delete();
            if ($row->isDraft()) {
                $row->delete();

                return $row;
            }
            $row->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

            return $row;
        });
    }

    /**
     * Create an Additional Salary already submitted by the system (source document approved elsewhere).
     *
     * @param  array<string, mixed>  $values
     */
    public function createSystem(array $values): AdditionalSalary
    {
        $row = new AdditionalSalary($values);
        $this->assertSubmittable($row);
        $row->forceFill(['status' => DocumentStatus::Submitted, 'submitted_at' => now()])->save();

        return $row;
    }

    public static function componentAllowed(SalaryComponent $component): bool
    {
        if ($component->statutory_key !== null) {
            return in_array($component->statutory_key, [StatutoryKey::Zakat, StatutoryKey::Cp38], true);
        }

        return in_array($component->kind, [ComponentKind::Earning, ComponentKind::Deduction], true)
            && $component->calculation !== CalculationMethod::Statutory;
    }

    private function assertSubmittable(AdditionalSalary $row): void
    {
        $periods = $row->kind === AdditionalKind::OneOff
            ? [$row->period]
            : [$row->from_period];

        if ($row->mode === AdditionalMode::Replace) {
            foreach ($periods as $period) {
                if ($period === null) {
                    continue;
                }
                $assignment = SalaryAssignment::effectiveFor($row->employee_id, $period);
                $inStructure = $assignment !== null && DB::table('salary_structure_rows')
                    ->where('salary_structure_id', $assignment->salary_structure_id)
                    ->where('salary_component_id', $row->salary_component_id)->exists();
                if (! $inStructure) {
                    throw new PayrollRuleViolation('payroll.errors.replace_not_in_structure', ['number' => $row->number ?? '']);
                }
            }
        }

        $others = AdditionalSalary::query()->where('employee_id', $row->employee_id)
            ->where('salary_component_id', $row->salary_component_id)
            ->where('status', DocumentStatus::Submitted->value)
            ->when($row->exists, fn ($q) => $q->whereKeyNot($row->id))
            ->get();

        foreach ($others as $other) {
            if ($row->mode === AdditionalMode::Replace && $other->mode === AdditionalMode::Replace && $this->overlaps($row, $other)) {
                throw new PayrollRuleViolation('payroll.errors.replace_duplicate', ['number' => $other->number]);
            }
            if ($row->kind === AdditionalKind::Recurring && $other->kind === AdditionalKind::Recurring
                && $row->mode === $other->mode && $this->overlaps($row, $other)) {
                throw new PayrollRuleViolation('payroll.errors.recurring_overlap', ['number' => $other->number]);
            }
        }
    }

    private function overlaps(AdditionalSalary $a, AdditionalSalary $b): bool
    {
        [$aFrom, $aTo] = $a->kind === AdditionalKind::OneOff ? [$a->period, $a->period] : [$a->from_period, $a->to_period];
        [$bFrom, $bTo] = $b->kind === AdditionalKind::OneOff ? [$b->period, $b->period] : [$b->from_period, $b->to_period];
        if ($aFrom === null || $bFrom === null) {
            return false;
        }

        return ($aTo === null || $bFrom->lessThanOrEqualTo($aTo)) && ($bTo === null || $aFrom->lessThanOrEqualTo($bTo));
    }
}
