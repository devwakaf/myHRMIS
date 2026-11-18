<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Enums\ActionType;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Enums\AssigneeOrigin;
use App\Domain\Shared\Approval\Enums\Decision;
use App\Domain\Shared\Approval\Enums\OnNoApprover;
use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Enums\SkipReason;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Events\ApprovalCancelled;
use App\Domain\Shared\Approval\Events\ApprovalCompleted;
use App\Domain\Shared\Approval\Events\ApprovalRejected;
use App\Domain\Shared\Approval\Events\ApprovalStepActivated;
use App\Domain\Shared\Approval\Events\ApprovalStepCompleted;
use App\Domain\Shared\Approval\Events\ApprovalSubmitted;
use App\Domain\Shared\Approval\Exceptions\AlreadyDecided;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use App\Domain\Shared\Approval\Exceptions\NoActiveWorkflow;
use App\Domain\Shared\Approval\Exceptions\NoApproverFound;
use App\Domain\Shared\Approval\Exceptions\NotAnAssignee;
use App\Domain\Shared\Approval\Exceptions\RequestNotPending;
use App\Domain\Shared\Approval\Exceptions\SelfApprovalForbidden;
use App\Domain\Shared\Approval\Models\ApprovalAction;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Models\ApprovalRequestStep;
use App\Domain\Shared\Approval\Models\ApprovalStepAssignee;
use App\Domain\Shared\Approval\Models\ApprovalWorkflow;
use App\Domain\Shared\Approval\Models\ApprovalWorkflowStep;
use App\Domain\Shared\Approval\Notifications\ApproverReassigned;
use App\Domain\Shared\Approval\Notifications\NoApproverAlert;
use App\Domain\Shared\Approval\Services\ApproverResolver;
use App\Domain\Shared\Approval\Services\ConditionEvaluator;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Multi-level approval engine runtime (A6.6, A6.7).
 *
 * All decisions run in one transaction with a row lock on the request. Module
 * hooks run inside it; events are dispatched after commit.
 */
final class ApprovalService
{
    public function __construct(
        private readonly ApprovalTypeRegistry $registry,
        private readonly ApproverResolver $resolver,
        private readonly ConditionEvaluator $conditions,
        private readonly OrganisationMembership $membership,
        private readonly RoleService $roles,
    ) {}

    // ------------------------------------------------------------------ queries

    public function activeWorkflow(string $approvalType, int $organisationId): ApprovalWorkflow
    {
        $workflow = ApprovalWorkflow::acrossOrganisations()
            ->where('organisation_id', $organisationId)
            ->activeFor($approvalType)
            ->with('steps')
            ->first();

        if ($workflow === null || $workflow->steps->isEmpty()) {
            throw new NoActiveWorkflow('approval.errors.no_active_workflow', [
                'type' => $this->registry->has($approvalType) ? $this->registry->get($approvalType)->label() : $approvalType,
            ]);
        }

        return $workflow;
    }

    /**
     * Inbox: requests with an active step where the user is a pending assignee.
     *
     * @return Builder<ApprovalRequest>
     */
    public function pendingFor(User $user): Builder
    {
        return ApprovalRequest::query()
            ->where('status', RequestStatus::Pending->value)
            ->whereHas('steps', function (Builder $q) use ($user): void {
                $q->where('status', StepStatus::Active->value)
                    ->whereHas('assignees', fn (Builder $a) => $a
                        ->where('user_id', $user->id)
                        ->where('decision', AssigneeDecision::Pending->value));
            });
    }

    public function canView(User $user, ApprovalRequest $request): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        if ($request->submitted_by === $user->id) {
            return true;
        }

        $applicantUserId = Employee::acrossOrganisations()->whereKey($request->applicant_employee_id)->value('user_id');
        if ($applicantUserId !== null && (int) $applicantUserId === $user->id) {
            return true;
        }

        $isAssignee = ApprovalStepAssignee::query()
            ->where('user_id', $user->id)
            ->whereHas('step', fn (Builder $q) => $q
                ->where('approval_request_id', $request->id)
                ->where('status', '!=', StepStatus::Waiting->value))
            ->exists();
        if ($isAssignee) {
            return true;
        }

        if (! $this->registry->has($request->approval_type)) {
            return false;
        }

        $type = $this->registry->get($request->approval_type);

        return $this->roles->can($user, $request->organisation_id, $type->viewAllPermission)
            || $this->roles->can($user, $request->organisation_id, $type->approveAnyPermission);
    }

    /** The user is a pending assignee of the active step. */
    public function canDecide(User $user, ApprovalRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        return ApprovalStepAssignee::query()
            ->where('user_id', $user->id)
            ->where('decision', AssigneeDecision::Pending->value)
            ->whereHas('step', fn (Builder $q) => $q
                ->where('approval_request_id', $request->id)
                ->where('status', StepStatus::Active->value))
            ->exists();
    }

    public function canOverride(User $user, ApprovalRequest $request): bool
    {
        if (! $request->isPending() || ! $this->registry->has($request->approval_type)) {
            return false;
        }

        $workflow = $request->workflow;

        return $workflow->allow_override
            && $this->roles->can($user, $request->organisation_id, $this->registry->get($request->approval_type)->approveAnyPermission);
    }

    // ------------------------------------------------------------------ preview / submit

    /**
     * @param  Approvable  $doc  (a Model in practice; previews may pass an in-memory subject)
     * @param  array<string, mixed>  $choices
     */
    public function preview(Approvable $doc, array $choices = [], ?User $submitter = null): ApprovalRoute
    {
        $applicant = $doc->approvalApplicant();
        $workflow = $this->activeWorkflow($doc->approvalType(), (int) $applicant->organisation_id);
        $attributes = $this->collectAttributes($doc, $applicant);

        $steps = [];
        foreach ($workflow->steps as $step) {
            $steps[] = $this->dryRunStep($workflow, $step, $applicant, $doc->approvalType(), $attributes, $choices, $submitter, false);
        }

        return new ApprovalRoute($workflow->id, $workflow->version, $workflow->name, $steps);
    }

    /**
     * @param  Model&Approvable  $doc
     * @param  array<string, mixed>  $choices
     */
    public function submit(Approvable $doc, User $actor, array $choices = []): ApprovalRequest
    {
        return DB::transaction(function () use ($doc, $actor, $choices): ApprovalRequest {
            $applicant = $doc->approvalApplicant();
            $orgId = (int) $applicant->organisation_id;
            $type = $doc->approvalType();
            $this->registry->get($type);

            $existing = ApprovalRequest::query()->withoutGlobalScopes()
                ->where('approvable_type', $doc->getMorphClass())
                ->where('approvable_id', $doc->getKey())
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                throw new RequestNotPending('approval.errors.already_submitted');
            }

            $workflow = $this->activeWorkflow($type, $orgId);
            $attributes = $this->collectAttributes($doc, $applicant);

            // Dry run first: a `fail` step without approvers aborts before anything is saved.
            foreach ($workflow->steps as $step) {
                $outcome = $this->dryRunStep($workflow, $step, $applicant, $type, $attributes, $choices, $actor, true);
                if ($outcome['outcome'] === 'no_approver_fail') {
                    throw new NoApproverFound($step);
                }
                if ($outcome['outcome'] === 'choice_required') {
                    throw DomainValidation::withMessages([
                        'choices.'.$step->id => __('approval.errors.choice_required', ['step' => $step->name]),
                    ]);
                }
            }

            $request = ApprovalRequest::query()->create([
                'organisation_id' => $orgId,
                'approvable_type' => $doc->getMorphClass(),
                'approvable_id' => $doc->getKey(),
                'approval_type' => $type,
                'approval_workflow_id' => $workflow->id,
                'applicant_employee_id' => $applicant->id,
                'submitted_by' => $actor->id,
                'status' => RequestStatus::Pending,
                'frozen_attributes' => $attributes,
                'choices' => $choices === [] ? null : $choices,
                'submitted_at' => now(),
            ]);

            foreach ($workflow->steps as $step) {
                $skipped = ! $this->conditions->passes($step->condition, $attributes);
                $row = $request->steps()->create([
                    'approval_workflow_step_id' => $step->id,
                    'sequence' => $step->sequence,
                    'name' => $step->name,
                    'mode' => $step->mode,
                    'status' => $skipped ? StepStatus::Skipped : StepStatus::Waiting,
                    'skip_reason' => $skipped ? SkipReason::ConditionFalse : null,
                    'completed_at' => $skipped ? now() : null,
                ]);
                if ($skipped) {
                    $this->log($request, $row, null, ActionType::StepSkipped, null, ['reason' => SkipReason::ConditionFalse->value]);
                }
            }

            $this->log($request, null, $actor, ActionType::Submitted);
            $doc->mirrorApprovalStatus(RequestStatus::Pending, $actor->id);

            ApprovalSubmitted::dispatch($request);

            $this->advance($request, $doc, $actor, isFirst: true);

            return $request->refresh();
        });
    }

    // ------------------------------------------------------------------ decisions

    public function approve(ApprovalRequest $request, User $actor, ?string $note = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $note): ApprovalRequest {
            $request = $this->lock($request);
            [$step, $assignee] = $this->assigneeForDecision($request, $actor);
            $this->guardSelf($request, $step, $actor);

            $assignee->update(['decision' => AssigneeDecision::Approved, 'decided_at' => now(), 'note' => $note]);
            $this->log($request, $step, $actor, ActionType::Approved, $note);

            $stillPending = $step->assignees()->where('decision', AssigneeDecision::Pending->value)->exists();
            if ($step->mode->value === 'any_one' || ! $stillPending) {
                $this->completeStep($request, $step);
                $this->advance($request, $this->document($request), $actor);
            }

            return $request->refresh();
        });
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): ApprovalRequest
    {
        $this->requireText($note, 'note');

        return DB::transaction(function () use ($request, $actor, $note): ApprovalRequest {
            $request = $this->lock($request);
            [$step, $assignee] = $this->assigneeForDecision($request, $actor);
            $this->guardSelf($request, $step, $actor);

            $assignee->update(['decision' => AssigneeDecision::Rejected, 'decided_at' => now(), 'note' => $note]);
            $this->log($request, $step, $actor, ActionType::Rejected, $note);
            $this->rejectRequest($request, $step, $actor);

            return $request->refresh();
        });
    }

    public function override(ApprovalRequest $request, User $actor, Decision $decision, string $reason): ApprovalRequest
    {
        $this->requireText($reason, 'reason');

        return DB::transaction(function () use ($request, $actor, $decision, $reason): ApprovalRequest {
            $request = $this->lock($request);
            $this->ensurePending($request);

            if (! $this->canOverride($actor, $request)) {
                throw new NotAnAssignee('approval.errors.override_not_allowed');
            }

            $step = $this->activeStepOrFail($request);
            $this->guardSelf($request, $step, $actor);

            $step->assignees()->where('decision', AssigneeDecision::Pending->value)
                ->update(['decision' => AssigneeDecision::NotRequired->value, 'decided_at' => now()]);

            if ($decision === Decision::Approve) {
                $this->log($request, $step, $actor, ActionType::OverrideApproved, $reason);
                $this->completeStep($request, $step);
                $this->advance($request, $this->document($request), $actor);
            } else {
                $this->log($request, $step, $actor, ActionType::OverrideRejected, $reason);
                $this->rejectRequest($request, $step, $actor);
            }

            return $request->refresh();
        });
    }

    /**
     * Withdraw a pending request or cancel an approved one. Null actor = system.
     * Whether an approved request may be cancelled is the module's decision.
     */
    public function cancel(ApprovalRequest $request, ?User $actor, string $reason): ApprovalRequest
    {
        $this->requireText($reason, 'reason');

        return DB::transaction(function () use ($request, $actor, $reason): ApprovalRequest {
            $request = $this->lock($request);

            if (! in_array($request->status, [RequestStatus::Pending, RequestStatus::Approved], true)) {
                throw new RequestNotPending('approval.errors.not_pending');
            }

            $wasApproved = $request->status === RequestStatus::Approved;
            $notify = [];

            if (! $wasApproved) {
                foreach ($request->steps()->whereIn('status', [StepStatus::Active->value, StepStatus::Waiting->value])->get() as $step) {
                    if ($step->status === StepStatus::Active) {
                        $pending = $step->assignees()->where('decision', AssigneeDecision::Pending->value);
                        $notify = array_merge($notify, $pending->pluck('user_id')->map(fn ($id) => (int) $id)->all());
                        $step->assignees()->where('decision', AssigneeDecision::Pending->value)
                            ->update(['decision' => AssigneeDecision::NotRequired->value, 'decided_at' => now()]);
                    }
                    $step->update(['status' => StepStatus::Cancelled, 'completed_at' => now()]);
                }
            }

            $request->update([
                'status' => RequestStatus::Cancelled,
                'current_sequence' => null,
                'completed_at' => now(),
                'cancel_reason' => $reason,
            ]);
            $this->log($request, null, $actor, ActionType::Cancelled, $reason, ['was_approved' => $wasApproved]);

            $doc = $this->document($request);
            $doc->mirrorApprovalStatus(RequestStatus::Cancelled, $actor?->id, $reason);
            $doc->onApprovalCancelled($request, $wasApproved);

            ApprovalCancelled::dispatch($request, $wasApproved, $actor?->id, array_values(array_unique($notify)));

            return $request->refresh();
        });
    }

    /**
     * Replace the active step's assignees with $userIds (send the current ones too to "add").
     *
     * @param  list<int>  $userIds
     */
    public function reassign(ApprovalRequest $request, User $actor, array $userIds, string $reason): ApprovalRequest
    {
        $this->requireText($reason, 'reason');

        return DB::transaction(function () use ($request, $actor, $userIds, $reason): ApprovalRequest {
            $request = $this->lock($request);
            $this->ensurePending($request);

            if (! $this->canOverride($actor, $request)) {
                throw new NotAnAssignee('approval.errors.override_not_allowed');
            }

            $step = $this->activeStepOrFail($request);
            $members = $this->membership->memberUserIds($request->organisation_id);
            $applicantUserId = $this->applicantUserId($request);
            $userIds = array_values(array_unique(array_map('intval', $userIds)));

            if ($userIds === []) {
                throw DomainValidation::withMessages(['user_ids' => __('approval.errors.reassign_empty')]);
            }
            foreach ($userIds as $id) {
                if (! in_array($id, $members, true)) {
                    throw DomainValidation::withMessages(['user_ids' => __('approval.errors.reassign_not_member')]);
                }
                if ($id === $applicantUserId && $request->workflow->prevent_self_approval) {
                    throw new SelfApprovalForbidden('approval.errors.self_approval');
                }
            }

            $current = $step->assignees()->get();
            foreach ($current as $assignee) {
                if ($assignee->decision === AssigneeDecision::Pending && ! in_array($assignee->user_id, $userIds, true)) {
                    $assignee->update(['decision' => AssigneeDecision::Removed, 'decided_at' => now(), 'note' => $reason]);
                }
            }

            $added = [];
            foreach ($userIds as $id) {
                $row = $current->firstWhere('user_id', $id);
                if ($row === null) {
                    $step->assignees()->create(['user_id' => $id, 'origin' => AssigneeOrigin::Reassigned, 'decision' => AssigneeDecision::Pending]);
                    $added[] = $id;
                } elseif ($row->decision === AssigneeDecision::Removed) {
                    $row->update(['decision' => AssigneeDecision::Pending, 'origin' => AssigneeOrigin::Reassigned, 'decided_at' => null]);
                    $added[] = $id;
                }
                $this->grantPelulus($id, $request->organisation_id);
            }

            $this->log($request, $step, $actor, ActionType::Reassigned, $reason, ['user_ids' => $userIds, 'added' => $added]);

            if ($added !== []) {
                $requestId = $request->id;
                DB::afterCommit(function () use ($added, $requestId): void {
                    $request = ApprovalRequest::query()->withoutGlobalScopes()->find($requestId);
                    if ($request !== null) {
                        Notification::send(User::query()->whereIn('id', $added)->get(), new ApproverReassigned($request));
                    }
                });
            }

            return $request->refresh();
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * Activate the next waiting steps in order; complete the request when none remain.
     *
     * @param  Model&Approvable  $doc
     */
    private function advance(ApprovalRequest $request, Approvable $doc, ?User $actor, bool $isFirst = false): void
    {
        $workflow = $request->workflow;
        $applicant = $doc->approvalApplicant();

        while (true) {
            /** @var ApprovalRequestStep|null $next */
            $next = $request->steps()->where('status', StepStatus::Waiting->value)->orderBy('sequence')->first();

            if ($next === null) {
                $this->completeRequest($request, $doc, $actor);

                return;
            }

            if ($this->activateStep($request, $next, $workflow, $applicant, $isFirst)) {
                return;
            }
        }
    }

    /**
     * @return bool true if the step is now active (waiting for people), false if skipped
     */
    private function activateStep(ApprovalRequest $request, ApprovalRequestStep $row, ApprovalWorkflow $workflow, Employee $applicant, bool $isFirst): bool
    {
        $config = $row->workflowStep;
        $resolved = $this->resolver->resolve($config, $applicant, $request->approval_type, $request->choices ?? [], false);
        $userIds = $resolved->userIds;

        $applicantUserId = $applicant->user_id === null ? null : (int) $applicant->user_id;
        $removedApplicant = false;
        if ($applicantUserId !== null && in_array($applicantUserId, $userIds, true)
            && ($workflow->prevent_self_approval || $config->skip_if_applicant)) {
            $userIds = array_values(array_diff($userIds, [$applicantUserId]));
            $removedApplicant = true;
        }

        if ($config->exclude_submitter && $request->submitted_by !== null) {
            $userIds = array_values(array_diff($userIds, [(int) $request->submitted_by]));
        }

        if ($userIds === [] && $removedApplicant && $config->skip_if_applicant) {
            $this->skip($request, $row, SkipReason::ApplicantOnly);

            return false;
        }

        if ($userIds !== [] && $config->skip_if_same_as_previous) {
            $previous = $request->steps()
                ->where('sequence', '<', $row->sequence)
                ->where('status', StepStatus::Approved->value)
                ->orderByDesc('sequence')
                ->first();
            if ($previous !== null) {
                $approvedBy = $previous->assignees()->where('decision', AssigneeDecision::Approved->value)
                    ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
                if ($approvedBy !== [] && array_diff($userIds, $approvedBy) === []) {
                    $this->skip($request, $row, SkipReason::SameAsPrevious);

                    return false;
                }
            }
        }

        if ($userIds === [] && $config->on_no_approver === OnNoApprover::Skip) {
            $this->skip($request, $row, SkipReason::NoApprover);

            return false;
        }

        $row->update(['status' => StepStatus::Active, 'activated_at' => now()]);
        $request->update(['current_sequence' => $row->sequence]);

        foreach ($userIds as $userId) {
            $row->assignees()->create([
                'user_id' => $userId,
                'origin' => $resolved->origin,
                'decision' => AssigneeDecision::Pending,
            ]);
            $this->grantPelulus($userId, $request->organisation_id);
        }

        $this->log($request, $row, null, ActionType::StepActivated, null, ['assignees' => $userIds]);

        if ($userIds === []) {
            // hr_queue, or `fail` discovered after submit: stays active in "Tanpa Pelulus".
            if ($config->on_no_approver === OnNoApprover::Fail) {
                $this->alertNoApprover($request, $row);
            }
        }

        ApprovalStepActivated::dispatch($request, $row, $userIds, $isFirst);

        return true;
    }

    private function skip(ApprovalRequest $request, ApprovalRequestStep $row, SkipReason $reason): void
    {
        $row->update(['status' => StepStatus::Skipped, 'skip_reason' => $reason, 'completed_at' => now()]);
        $this->log($request, $row, null, ActionType::StepSkipped, null, ['reason' => $reason->value]);
    }

    private function completeStep(ApprovalRequest $request, ApprovalRequestStep $step): void
    {
        $step->assignees()->where('decision', AssigneeDecision::Pending->value)
            ->update(['decision' => AssigneeDecision::NotRequired->value, 'decided_at' => now()]);
        $step->update(['status' => StepStatus::Approved, 'completed_at' => now()]);

        ApprovalStepCompleted::dispatch($request, $step);
    }

    /**
     * @param  Model&Approvable  $doc
     */
    private function completeRequest(ApprovalRequest $request, Approvable $doc, ?User $actor): void
    {
        $check = $doc->checkApprovalCompletion($request);
        if (! $check->passed) {
            throw new ApprovalCompletionBlocked($check);
        }

        $request->update([
            'status' => RequestStatus::Approved,
            'current_sequence' => null,
            'completed_at' => now(),
        ]);
        $this->log($request, null, $actor, ActionType::Completed);

        $doc->mirrorApprovalStatus(RequestStatus::Approved, $actor?->id);
        $doc->onApprovalCompleted($request);

        ApprovalCompleted::dispatch($request);
    }

    private function rejectRequest(ApprovalRequest $request, ApprovalRequestStep $step, ?User $actor): void
    {
        $step->assignees()->where('decision', AssigneeDecision::Pending->value)
            ->update(['decision' => AssigneeDecision::NotRequired->value, 'decided_at' => now()]);
        $step->update(['status' => StepStatus::Rejected, 'completed_at' => now()]);

        $request->steps()->where('status', StepStatus::Waiting->value)
            ->update(['status' => StepStatus::Cancelled->value, 'completed_at' => now()]);

        $request->update([
            'status' => RequestStatus::Rejected,
            'current_sequence' => null,
            'completed_at' => now(),
        ]);

        $doc = $this->document($request);
        $doc->mirrorApprovalStatus(RequestStatus::Rejected, $actor?->id);
        $doc->onApprovalRejected($request);

        ApprovalRejected::dispatch($request);
    }

    /**
     * Find the actor's pending assignee row on the active step.
     *
     * @return array{0: ApprovalRequestStep, 1: ApprovalStepAssignee}
     */
    private function assigneeForDecision(ApprovalRequest $request, User $actor): array
    {
        $rows = ApprovalStepAssignee::query()
            ->where('user_id', $actor->id)
            ->whereHas('step', fn (Builder $q) => $q->where('approval_request_id', $request->id))
            ->with('step')
            ->get();

        // A co-assignee whose step was already decided by someone else.
        if ($rows->contains(fn (ApprovalStepAssignee $a) => $a->decision === AssigneeDecision::NotRequired)) {
            throw new AlreadyDecided('approval.errors.already_decided');
        }

        $this->ensurePending($request);
        $step = $this->activeStepOrFail($request);

        $mine = $rows->first(fn (ApprovalStepAssignee $a) => $a->approval_request_step_id === $step->id);
        if ($mine === null) {
            if ($rows->isNotEmpty()) {
                throw new AlreadyDecided('approval.errors.already_decided');
            }
            throw new NotAnAssignee('approval.errors.not_assignee');
        }

        if ($mine->decision !== AssigneeDecision::Pending) {
            throw new AlreadyDecided('approval.errors.already_decided');
        }

        return [$step, $mine];
    }

    /** Self-approval guard (A6.6) plus the generic submitter guard (lead decision 5). */
    private function guardSelf(ApprovalRequest $request, ApprovalRequestStep $step, User $actor): void
    {
        $applicantUserId = $this->applicantUserId($request);
        if ($applicantUserId === $actor->id && $request->workflow->prevent_self_approval) {
            throw new SelfApprovalForbidden('approval.errors.self_approval');
        }

        if ($step->workflowStep->exclude_submitter && $request->submitted_by === $actor->id && $applicantUserId !== $actor->id) {
            throw new SelfApprovalForbidden('approval.errors.submitter_approval');
        }
    }

    private function applicantUserId(ApprovalRequest $request): ?int
    {
        $id = Employee::acrossOrganisations()->whereKey($request->applicant_employee_id)->value('user_id');

        return $id === null ? null : (int) $id;
    }

    private function ensurePending(ApprovalRequest $request): void
    {
        if ($request->status !== RequestStatus::Pending) {
            throw new RequestNotPending('approval.errors.not_pending');
        }
    }

    private function activeStepOrFail(ApprovalRequest $request): ApprovalRequestStep
    {
        $step = $request->steps()->where('status', StepStatus::Active->value)->lockForUpdate()->first();
        if ($step === null) {
            throw new RequestNotPending('approval.errors.not_pending');
        }

        return $step;
    }

    private function lock(ApprovalRequest $request): ApprovalRequest
    {
        return ApprovalRequest::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
    }

    /**
     * @return Model&Approvable
     */
    private function document(ApprovalRequest $request): Approvable
    {
        $doc = $request->approvable()->withoutGlobalScopes()->first();
        if (! $doc instanceof Approvable) {
            throw new \LogicException("Approvable document for request {$request->id} not found.");
        }

        return $doc;
    }

    /**
     * Frozen condition context: the document's attributes plus the common applicant.* ones.
     *
     * @return array<string, scalar|null>
     */
    private function collectAttributes(Approvable $doc, Employee $applicant): array
    {
        $applicant->loadMissing(['grade', 'employmentType']);

        return array_merge($doc->approvalAttributes(), [
            'applicant.department_id' => $applicant->department_id,
            'applicant.branch_id' => $applicant->branch_id,
            'applicant.grade_rank' => $applicant->grade?->rank,
            'applicant.employment_type_category' => $applicant->employmentType?->category->value,
            'applicant.citizenship_status' => $applicant->citizenship_status->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $choices
     * @return array{workflow_step_id: int, sequence: int, name: string, mode: string, outcome: string, skip_reason: string|null, approvers: list<array{id: int, name: string}>, candidates: list<array{id: int, name: string}>, default_choice: int|null}
     */
    private function dryRunStep(
        ApprovalWorkflow $workflow,
        ApprovalWorkflowStep $step,
        Employee $applicant,
        string $type,
        array $attributes,
        array $choices,
        ?User $submitter,
        bool $strict,
    ): array {
        $base = [
            'workflow_step_id' => $step->id,
            'sequence' => $step->sequence,
            'name' => $step->name,
            'mode' => $step->mode->value,
            'outcome' => 'pending',
            'skip_reason' => null,
            'approvers' => [],
            'candidates' => [],
            'default_choice' => null,
        ];

        if (! $this->conditions->passes($step->condition, $attributes)) {
            return array_merge($base, ['outcome' => 'skipped', 'skip_reason' => SkipReason::ConditionFalse->value]);
        }

        $resolved = $this->resolver->resolve($step, $applicant, $type, $choices, $strict);
        $userIds = $resolved->userIds;
        $applicantUserId = $applicant->user_id === null ? null : (int) $applicant->user_id;
        $removedApplicant = false;
        if ($applicantUserId !== null && in_array($applicantUserId, $userIds, true)
            && ($workflow->prevent_self_approval || $step->skip_if_applicant)) {
            $userIds = array_values(array_diff($userIds, [$applicantUserId]));
            $removedApplicant = true;
        }
        if ($step->exclude_submitter && $submitter !== null) {
            $userIds = array_values(array_diff($userIds, [$submitter->id]));
        }

        $names = User::query()->whereIn('id', array_merge($userIds, $resolved->candidates))->pluck('name', 'id');
        $describe = fn (array $ids) => array_values(array_map(fn (int $id) => ['id' => $id, 'name' => (string) ($names[$id] ?? '')], $ids));

        $base['approvers'] = $describe($userIds);
        $base['candidates'] = $describe($resolved->candidates);
        $base['default_choice'] = $resolved->defaultChoice;

        if ($userIds === []) {
            if ($resolved->choiceRequired) {
                return array_merge($base, ['outcome' => 'choice_required']);
            }
            if ($removedApplicant && $step->skip_if_applicant) {
                return array_merge($base, ['outcome' => 'skipped', 'skip_reason' => SkipReason::ApplicantOnly->value]);
            }

            return array_merge($base, ['outcome' => match ($step->on_no_approver) {
                OnNoApprover::Fail => 'no_approver_fail',
                OnNoApprover::Skip => 'skipped',
                OnNoApprover::HrQueue => 'hr_queue',
            }, 'skip_reason' => $step->on_no_approver === OnNoApprover::Skip ? SkipReason::NoApprover->value : null]);
        }

        return $base;
    }

    private function grantPelulus(int $userId, int $organisationId): void
    {
        $user = User::query()->find($userId);
        if ($user !== null && ! $this->roles->hasRole($user, $organisationId, PermissionMatrix::PELULUS)) {
            $this->roles->assign($user, $organisationId, PermissionMatrix::PELULUS);
        }
    }

    private function alertNoApprover(ApprovalRequest $request, ApprovalRequestStep $row): void
    {
        $row->update(['no_approver_alerted_at' => now()]);
        $orgId = $request->organisation_id;
        $requestId = $request->id;
        $stepId = $row->id;

        DB::afterCommit(function () use ($orgId, $requestId, $stepId): void {
            $request = ApprovalRequest::query()->withoutGlobalScopes()->find($requestId);
            $step = ApprovalRequestStep::query()->find($stepId);
            if ($request === null || $step === null) {
                return;
            }
            $hr = $this->hrRecipients($orgId, $request->approval_type);
            if ($hr->isNotEmpty()) {
                Notification::send($hr, new NoApproverAlert($request, $step));
            }
        });
    }

    /**
     * HR users who can act on an unassigned step (override holders of the type).
     *
     * @return Collection<int, User>
     */
    public function hrRecipients(int $organisationId, string $approvalType): Collection
    {
        $permission = $this->registry->has($approvalType)
            ? $this->registry->get($approvalType)->approveAnyPermission
            : 'core.approval-workflows.manage';

        return User::query()->whereIn('id', $this->membership->usersWithPermission($organisationId, $permission))->get();
    }

    private function requireText(?string $value, string $field): void
    {
        if (trim((string) $value) === '') {
            throw DomainValidation::withMessages([$field => __('approval.errors.'.$field.'_required')]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function log(ApprovalRequest $request, ?ApprovalRequestStep $step, ?User $actor, ActionType $action, ?string $note = null, ?array $meta = null): void
    {
        ApprovalAction::query()->create([
            'organisation_id' => $request->organisation_id,
            'approval_request_id' => $request->id,
            'approval_request_step_id' => $step?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'note' => $note,
            'meta' => $meta,
        ]);
    }
}
