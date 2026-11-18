<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

/** To the applicant after each step (notify_applicant_each_step). */
final class ApprovalProgressed extends ApprovalNotification
{
    protected function subjectKey(): string
    {
        return 'approval.mail.progressed.subject';
    }

    protected function lineKey(): string
    {
        return 'approval.mail.progressed.line';
    }
}
