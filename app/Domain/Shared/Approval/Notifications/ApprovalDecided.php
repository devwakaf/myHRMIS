<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Models\ApprovalRequest;

/** To the applicant on the final outcome (outcome captured when queued). */
final class ApprovalDecided extends ApprovalNotification
{
    public readonly bool $approved;

    public function __construct(ApprovalRequest $request)
    {
        parent::__construct($request);
        $this->approved = $request->status === RequestStatus::Approved;
    }

    protected function subjectKey(): string
    {
        return $this->approved ? 'approval.mail.approved.subject' : 'approval.mail.rejected.subject';
    }

    protected function lineKey(): string
    {
        return $this->approved ? 'approval.mail.approved.line' : 'approval.mail.rejected.line';
    }
}
