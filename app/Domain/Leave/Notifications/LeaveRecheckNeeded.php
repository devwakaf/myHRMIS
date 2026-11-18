<?php

declare(strict_types=1);

namespace App\Domain\Leave\Notifications;

use App\Domain\Leave\Models\LeaveReviewTask;

/** To HR: an approved application needs review after a holiday change (spec-02 §3.14). */
final class LeaveRecheckNeeded extends LeaveNotice
{
    public function __construct(public readonly LeaveReviewTask $task) {}

    public function kind(): string
    {
        return 'recheck_needed';
    }

    public function params(): array
    {
        $application = $this->task->application()->withoutGlobalScopes()->first();

        return ['number' => (string) $application?->number, 'reason' => (string) __('leave.review_reasons.'.$this->task->reason)];
    }
}
