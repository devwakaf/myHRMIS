<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

/** To new assignees after an HR reassignment. */
final class ApproverReassigned extends ApprovalNotification
{
    protected function subjectKey(): string
    {
        return 'approval.mail.reassigned.subject';
    }

    protected function lineKey(): string
    {
        return 'approval.mail.reassigned.line';
    }
}
