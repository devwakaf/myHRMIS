<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

/** To assignees when their step activates. */
final class ApprovalRequested extends ApprovalNotification
{
    protected function subjectKey(): string
    {
        return 'approval.mail.requested.subject';
    }

    protected function lineKey(): string
    {
        return 'approval.mail.requested.line';
    }
}
