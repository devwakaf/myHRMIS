<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Jobs;

use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Approval\Enums\ActionType;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Models\ApprovalAction;
use App\Domain\Shared\Approval\Models\ApprovalRequestStep;
use App\Domain\Shared\Approval\Notifications\ApprovalReminder;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * Daily (08:00): remind pending assignees of steps active longer than
 * remind_after_days — at most once per day per step (A6.10).
 */
final class SendApprovalReminders implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $today = now()->startOfDay();

        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($orgId, $today): void {
                $steps = ApprovalRequestStep::query()
                    ->where('status', StepStatus::Active->value)
                    ->whereHas('request', fn ($q) => $q->where('organisation_id', $orgId))
                    ->whereHas('workflowStep', fn ($q) => $q->whereNotNull('remind_after_days'))
                    ->with(['workflowStep', 'request', 'assignees'])
                    ->get();

                foreach ($steps as $step) {
                    $days = (int) $step->workflowStep->remind_after_days;
                    if ($step->activated_at === null || $step->activated_at->copy()->startOfDay()->addDays($days)->greaterThan($today)) {
                        continue;
                    }
                    if ($step->last_reminded_at !== null && $step->last_reminded_at->greaterThanOrEqualTo($today)) {
                        continue;
                    }

                    $userIds = $step->assignees->where('decision', AssigneeDecision::Pending)->pluck('user_id')->all();
                    if ($userIds === []) {
                        continue;
                    }

                    $step->update(['last_reminded_at' => now()]);
                    ApprovalAction::query()->create([
                        'organisation_id' => $orgId,
                        'approval_request_id' => $step->approval_request_id,
                        'approval_request_step_id' => $step->id,
                        'actor_user_id' => null,
                        'action' => ActionType::Reminded,
                        'meta' => ['user_ids' => $userIds],
                    ]);
                    Notification::send(User::query()->whereIn('id', $userIds)->get(), new ApprovalReminder($step->request));
                }
            });
        }
    }
}
