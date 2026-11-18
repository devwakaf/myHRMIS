<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

/** To active assignees when a request is withdrawn or cancelled. */
final class ApprovalCancelledNotice extends ApprovalNotification
{
    protected function subjectKey(): string
    {
        return 'approval.mail.cancelled.subject';
    }

    protected function lineKey(): string
    {
        return 'approval.mail.cancelled.line';
    }
}
