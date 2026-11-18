<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Adapters;

use App\Domain\Leave\Contracts\AdditionalPayPort;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Payroll\Actions\AdditionalSalaryActions;
use App\Domain\Payroll\Enums\AdditionalKind;
use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;

/**
 * Leave encashment → Additional Salary (spec-02 §5.3, spec-04 §4.2). Created
 * already submitted by the system, idempotent via `leave_encashment:{id}`.
 * Falls back to the seeded GANTIAN_CUTI component when the leave type names none;
 * returns null (encashment stays awaiting_payroll) when no component exists.
 */
final class AdditionalPayAdapter implements AdditionalPayPort
{
    public const DEFAULT_COMPONENT = 'GANTIAN_CUTI';

    public function __construct(private readonly AdditionalSalaryActions $actions) {}

    public function createForEncashment(LeaveEncashment $encashment): ?int
    {
        return OrganisationContext::run((int) $encashment->organisation_id, function () use ($encashment): ?int {
            $ref = 'leave_encashment:'.$encashment->id;
            $existing = AdditionalSalary::query()->where('source_ref', $ref)->where('status', '!=', DocumentStatus::Cancelled->value)->first();
            if ($existing !== null) {
                return $existing->id;
            }

            $componentId = LeaveType::query()->whereKey($encashment->leave_type_id)->value('encashment_salary_component_id');
            $component = $componentId === null ? SalaryComponent::byCode(self::DEFAULT_COMPONENT) : SalaryComponent::query()->find($componentId);
            if ($component === null || ! $component->is_active) {
                return null;
            }

            $row = $this->actions->createSystem([
                'employee_id' => $encashment->employee_id,
                'salary_component_id' => $component->id,
                'kind' => AdditionalKind::OneOff,
                'period' => $encashment->encashment_date->startOfMonth()->toDateString(),
                'amount' => (string) $encashment->amount,
                'mode' => AdditionalMode::Add,
                'prorate' => false,
                'source_type' => AdditionalSource::LeaveEncashment,
                'source_id' => $encashment->id,
                'source_ref' => $ref,
                'reason' => (string) __('payroll.messages.encashment_reason', ['number' => $encashment->number]),
            ]);

            return $row->id;
        });
    }

    public function cancelForEncashment(LeaveEncashment $encashment): void
    {
        OrganisationContext::run((int) $encashment->organisation_id, function () use ($encashment): void {
            $row = AdditionalSalary::query()->where('source_ref', 'leave_encashment:'.$encashment->id)
                ->where('status', '!=', DocumentStatus::Cancelled->value)->first();
            if ($row === null) {
                return;
            }
            if ($row->applications()->whereHas('slip', fn ($q) => $q->where('status', DocumentStatus::Submitted->value))->exists()) {
                throw new PayrollRuleViolation('payroll.errors.encashment_consumed');
            }
            $this->actions->cancel($row, null, (string) __('payroll.messages.encashment_cancelled', ['number' => $encashment->number]), viaSource: true);
        });
    }
}
