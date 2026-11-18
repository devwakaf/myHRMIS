<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Models\ApprovalRequestStep;

/** To HR when an active step has no approver ("Tanpa Pelulus"). */
final class NoApproverAlert extends ApprovalNotification
{
    public function __construct(ApprovalRequest $request, public readonly ApprovalRequestStep $step)
    {
        parent::__construct($request);
    }

    protected function subjectKey(): string
    {
        return 'approval.mail.no_approver.subject';
    }

    protected function lineKey(): string
    {
        return 'approval.mail.no_approver.line';
    }

    protected function params(): array
    {
        return array_merge(parent::params(), ['step' => $this->step->name]);
    }
}
