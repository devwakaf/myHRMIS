<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Jobs;

use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Models\ApprovalRequestStep;
use App\Domain\Shared\Approval\Notifications\NoApproverAlert;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly (01:00): keep the derived `pelulus` role in sync with approver
 * references (A6.8) and flag active steps that have no active assignee
 * ("Tanpa Pelulus" + NoApproverAlert, once per step).
 */
final class SyncApproverRoles implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(RoleService $roles, OrganisationMembership $membership, ApprovalService $approvals): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($orgId, $roles, $membership, $approvals): void {
                $this->syncOrganisation((int) $orgId, $roles, $membership);
                $this->flagUnassignedSteps((int) $orgId, $membership, $approvals);
            });
        }
    }

    /**
     * @return list<int>
     */
    public function referencedUserIds(int $orgId): array
    {
        $named = DB::table('employee_approvers')
            ->join('employees', 'employees.id', '=', 'employee_approvers.employee_id')
            ->where('employees.organisation_id', $orgId)
            ->pluck('employee_approvers.user_id');

        $department = DB::table('department_approvers')
            ->join('departments', 'departments.id', '=', 'department_approvers.department_id')
            ->where('departments.organisation_id', $orgId)
            ->pluck('department_approvers.user_id');

        $specific = DB::table('approval_workflow_steps')
            ->join('approval_workflows', 'approval_workflows.id', '=', 'approval_workflow_steps.approval_workflow_id')
            ->where('approval_workflows.organisation_id', $orgId)
            ->where('approval_workflows.is_active', true)
            ->where('approval_workflow_steps.rule_type', RuleType::SpecificUser->value)
            ->pluck('approval_workflow_steps.rule_config')
            ->map(fn ($json) => (int) (json_decode((string) $json, true)['user_id'] ?? 0));

        $pending = DB::table('approval_step_assignees as a')
            ->join('approval_request_steps as s', 's.id', '=', 'a.approval_request_step_id')
            ->join('approval_requests as r', 'r.id', '=', 's.approval_request_id')
            ->where('r.organisation_id', $orgId)
            ->where('a.decision', AssigneeDecision::Pending->value)
            ->pluck('a.user_id');

        return $named->merge($department)->merge($specific)->merge($pending)
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    private function syncOrganisation(int $orgId, RoleService $roles, OrganisationMembership $membership): void
    {
        $referenced = $this->referencedUserIds($orgId);
        $holders = $membership->usersWithRole($orgId, PermissionMatrix::PELULUS);

        foreach (array_diff($holders, $referenced) as $userId) {
            $user = User::query()->find($userId);
            if ($user !== null) {
                $roles->remove($user, $orgId, PermissionMatrix::PELULUS);
            }
        }

        foreach (array_diff($referenced, $holders) as $userId) {
            $user = User::query()->find($userId);
            if ($user !== null && $user->is_active) {
                $roles->assign($user, $orgId, PermissionMatrix::PELULUS);
            }
        }
    }

    private function flagUnassignedSteps(int $orgId, OrganisationMembership $membership, ApprovalService $approvals): void
    {
        $activeMembers = $membership->memberUserIds($orgId);

        $steps = ApprovalRequestStep::query()
            ->where('status', StepStatus::Active->value)
            ->whereNull('no_approver_alerted_at')
            ->whereHas('request', fn ($q) => $q->where('organisation_id', $orgId))
            ->with(['request', 'assignees'])
            ->get();

        foreach ($steps as $step) {
            $hasActive = $step->assignees
                ->where('decision', AssigneeDecision::Pending)
                ->contains(fn ($a) => in_array((int) $a->user_id, $activeMembers, true));

            if ($hasActive) {
                continue;
            }

            $step->update(['no_approver_alerted_at' => now()]);
            $hr = $approvals->hrRecipients($orgId, $step->request->approval_type);
            if ($hr->isNotEmpty()) {
                Notification::send($hr, new NoApproverAlert($step->request, $step));
            }
        }
    }
}
