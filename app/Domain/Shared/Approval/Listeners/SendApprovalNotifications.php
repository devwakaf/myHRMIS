<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Listeners;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\Events\ApprovalCancelled;
use App\Domain\Shared\Approval\Events\ApprovalCompleted;
use App\Domain\Shared\Approval\Events\ApprovalRejected;
use App\Domain\Shared\Approval\Events\ApprovalStepActivated;
use App\Domain\Shared\Approval\Events\ApprovalStepCompleted;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Notifications\ApprovalCancelledNotice;
use App\Domain\Shared\Approval\Notifications\ApprovalDecided;
use App\Domain\Shared\Approval\Notifications\ApprovalProgressed;
use App\Domain\Shared\Approval\Notifications\ApprovalRequested;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * Notifications only (A6.10). Runs after commit because the events implement
 * ShouldDispatchAfterCommit; never used for mandatory side effects.
 */
final class SendApprovalNotifications
{
    public function handleStepActivated(ApprovalStepActivated $event): void
    {
        if ($event->assigneeUserIds !== []) {
            Notification::send(User::query()->whereIn('id', $event->assigneeUserIds)->get(), new ApprovalRequested($event->request));
        }
    }

    public function handleStepCompleted(ApprovalStepCompleted $event): void
    {
        $request = $event->request->fresh(['workflow']);
        if ($request === null || ! $request->workflow->notify_applicant_each_step || ! $request->isPending()) {
            return;
        }

        $applicant = $this->applicantUser($request);
        $applicant?->notify(new ApprovalProgressed($request));
    }

    public function handleCompleted(ApprovalCompleted $event): void
    {
        $this->applicantUser($event->request)?->notify(new ApprovalDecided($event->request));
    }

    public function handleRejected(ApprovalRejected $event): void
    {
        $this->applicantUser($event->request)?->notify(new ApprovalDecided($event->request));
    }

    public function handleCancelled(ApprovalCancelled $event): void
    {
        if ($event->notifyUserIds !== []) {
            Notification::send(User::query()->whereIn('id', $event->notifyUserIds)->get(), new ApprovalCancelledNotice($event->request));
        }
    }

    private function applicantUser(ApprovalRequest $request): ?User
    {
        $userId = Employee::acrossOrganisations()->whereKey($request->applicant_employee_id)->value('user_id');

        return $userId === null ? null : User::query()->find($userId);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ApprovalStepActivated::class => 'handleStepActivated',
            ApprovalStepCompleted::class => 'handleStepCompleted',
            ApprovalCompleted::class => 'handleCompleted',
            ApprovalRejected::class => 'handleRejected',
            ApprovalCancelled::class => 'handleCancelled',
        ];
    }
}
