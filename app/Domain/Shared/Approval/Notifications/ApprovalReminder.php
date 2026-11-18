<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

/** To pending assignees after remind_after_days (once per day per step). */
final class ApprovalReminder extends ApprovalNotification
{
    protected function subjectKey(): string
    {
        return 'approval.mail.reminder.subject';
    }

    protected function lineKey(): string
    {
        return 'approval.mail.reminder.line';
    }
}
