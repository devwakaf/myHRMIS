<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\SeparationReason;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DepartmentApprover;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeApprover;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Models\ApprovalStepAssignee;
use App\Domain\Shared\Approval\Models\ApprovalWorkflowStep;
use App\Domain\Shared\Support\DomainValidation;
use Carbon\CarbonImmutable;

/**
 * Pemberhentian (B2): HR records the relieving date (may be in the future).
 * Status stays active through the relieving date; ProcessDueSeparations finishes it.
 */
final class SeparateEmployee
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<string> warnings (approver references that will stop resolving)
     */
    public function handle(Employee $employee, array $data): array
    {
        if ($employee->status === EmployeeStatus::Left) {
            throw DomainValidation::withMessages(['relieving_date' => __('core.separation.already_left')]);
        }

        $relieving = CarbonImmutable::parse((string) ($data['relieving_date'] ?? ''));
        $resignation = empty($data['resignation_date']) ? null : CarbonImmutable::parse((string) $data['resignation_date']);
        $reason = SeparationReason::tryFrom((string) ($data['separation_reason'] ?? ''));

        $errors = [];
        if ($reason === null) {
            $errors['separation_reason'] = __('validation.required', ['attribute' => __('validation.attributes.separation_reason')]);
        }
        if ($relieving->lessThan($employee->date_of_joining)) {
            $errors['relieving_date'] = __('core.validation.before_joining');
        }
        if ($resignation !== null && $relieving->lessThan($resignation)) {
            $errors['relieving_date'] = __('core.validation.relieving_before_resignation');
        }
        DomainValidation::throwIf($errors);

        $this->guard($employee);

        $employee->update([
            'resignation_date' => $resignation?->toDateString(),
            'relieving_date' => $relieving->toDateString(),
            'separation_reason' => $reason,
            'separation_note' => $data['separation_note'] ?? null,
            'eligible_for_rehire' => isset($data['eligible_for_rehire']) ? (bool) $data['eligible_for_rehire'] : null,
        ]);

        return $this->approverReferenceWarnings($employee);
    }

    /** Blocked while active direct reports exist or the user is an active-step assignee. */
    public function guard(Employee $employee): void
    {
        $reports = Employee::query()->where('reports_to_id', $employee->id)
            ->where('status', '!=', EmployeeStatus::Left->value)->count();
        if ($reports > 0) {
            throw DomainValidation::withMessages(['reports' => __('core.separation.has_reports', ['count' => $reports])]);
        }

        if ($employee->user_id !== null) {
            $assigned = ApprovalStepAssignee::query()
                ->where('user_id', $employee->user_id)
                ->where('decision', AssigneeDecision::Pending->value)
                ->whereHas('step', fn ($q) => $q->where('status', StepStatus::Active->value)
                    ->whereHas('request', fn ($r) => $r->where('organisation_id', $employee->organisation_id)))
                ->exists();
            if ($assigned) {
                throw DomainValidation::withMessages(['approvals' => __('core.separation.has_pending_approvals')]);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function approverReferenceWarnings(Employee $employee): array
    {
        $warnings = [];
        if ($employee->user_id !== null) {
            $named = EmployeeApprover::query()->where('user_id', $employee->user_id)
                ->whereHas('employee', fn ($q) => $q->where('organisation_id', $employee->organisation_id))->count();
            $dept = DepartmentApprover::query()->where('user_id', $employee->user_id)
                ->whereHas('department', fn ($q) => $q->where('organisation_id', $employee->organisation_id))->count();
            $specific = ApprovalWorkflowStep::query()->where('rule_type', RuleType::SpecificUser->value)
                ->whereHas('workflow', fn ($q) => $q->where('organisation_id', $employee->organisation_id)->where('is_active', true))
                ->get()->filter(fn (ApprovalWorkflowStep $s) => (int) $s->config('user_id') === $employee->user_id)->count();

            if ($named > 0) {
                $warnings[] = (string) __('core.separation.warn_named', ['count' => $named]);
            }
            if ($dept > 0) {
                $warnings[] = (string) __('core.separation.warn_department', ['count' => $dept]);
            }
            if ($specific > 0) {
                $warnings[] = (string) __('core.separation.warn_specific', ['count' => $specific]);
            }
        }

        $heads = Department::query()->where('head_employee_id', $employee->id)->count();
        if ($heads > 0) {
            $warnings[] = (string) __('core.separation.warn_head', ['count' => $heads]);
        }

        return $warnings;
    }
}
