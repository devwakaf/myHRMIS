<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Events;

use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Models\ApprovalRequestStep;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit (A6.7): notifications and loose reactions only. */
final class ApprovalStepCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly ApprovalRequestStep $step,
    ) {}
}
