<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Events;

use App\Domain\Shared\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit for every cancellation (pending withdrawal, approved cancellation, system). */
final class ApprovalCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $notifyUserIds  assignees who were active when it was cancelled
     */
    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly bool $wasApproved,
        public readonly ?int $actorId,
        public readonly array $notifyUserIds = [],
    ) {}
}
