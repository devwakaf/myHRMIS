<?php

declare(strict_types=1);

namespace App\Domain\Leave\Policies;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Models\ApprovalStepAssignee;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;

/**
 * Permohonan Cuti access (spec-02 §6.5). "O" = own (the applicant), "A" = an
 * assignee of an active or completed step; MC attachments are health data and
 * are limited to HR (attachments.view), the applicant and those assignees.
 */
final class LeaveApplicationPolicy
{
    public function __construct(private readonly RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'leave.application.view-all');
    }

    public function view(User $user, LeaveApplication $application): bool
    {
        if (! $this->sameOrg($application)) {
            return false;
        }

        return $this->isApplicant($user, $application)
            || $this->can($user, 'leave.application.view-all')
            || $this->isAssignee($user, $application);
    }

    /** Apply for oneself (Kakitangan) or on behalf of others (HR). */
    public function applyFor(User $user, Employee $employee): bool
    {
        if ($employee->user_id !== null && (int) $employee->user_id === $user->id) {
            return $this->can($user, 'leave.application.apply') || $this->can($user, 'leave.application.create-for-others');
        }

        return $this->can($user, 'leave.application.create-for-others');
    }

    public function update(User $user, LeaveApplication $application): bool
    {
        return $application->status === ApprovalStatus::Draft && $this->ownsDraft($user, $application);
    }

    public function delete(User $user, LeaveApplication $application): bool
    {
        return $this->update($user, $application);
    }

    public function submit(User $user, LeaveApplication $application): bool
    {
        return $this->update($user, $application);
    }

    public function withdraw(User $user, LeaveApplication $application): bool
    {
        return $application->status === ApprovalStatus::Pending
            && ($this->isApplicant($user, $application)
                || $application->submitted_by === $user->id
                || $this->can($user, 'leave.application.create-for-others'));
    }

    public function viewAttachments(User $user, LeaveApplication $application): bool
    {
        if (! $this->sameOrg($application)) {
            return false;
        }

        return $this->isApplicant($user, $application)
            || $this->can($user, 'leave.attachments.view')
            || $this->isAssignee($user, $application);
    }

    public function addAttachment(User $user, LeaveApplication $application): bool
    {
        if (in_array($application->status, [ApprovalStatus::Draft, ApprovalStatus::Pending], true)) {
            return $this->ownsDraft($user, $application) || $this->can($user, 'leave.attachments.view');
        }

        return $application->status === ApprovalStatus::Approved && $this->can($user, 'leave.attachments.view');
    }

    private function ownsDraft(User $user, LeaveApplication $application): bool
    {
        return $this->isApplicant($user, $application)
            || ($application->applied_by_user_id === $user->id && $this->can($user, 'leave.application.create-for-others'));
    }

    private function isApplicant(User $user, LeaveApplication $application): bool
    {
        $userId = Employee::query()->whereKey($application->employee_id)->value('user_id');

        return $userId !== null && (int) $userId === $user->id;
    }

    private function isAssignee(User $user, LeaveApplication $application): bool
    {
        $request = $application->approvalRequest()->first();
        if ($request === null) {
            return false;
        }

        return ApprovalStepAssignee::query()->where('user_id', $user->id)
            ->whereHas('step', fn ($q) => $q->where('approval_request_id', $request->id)->where('status', '!=', StepStatus::Waiting->value))
            ->exists();
    }

    private function can(User $user, string $permission): bool
    {
        return $user->is_super_admin || (OrganisationContext::has() && $this->roles->can($user, OrganisationContext::id(), $permission));
    }

    private function sameOrg(LeaveApplication $application): bool
    {
        return OrganisationContext::has() && $application->organisation_id === OrganisationContext::id();
    }
}
