<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Events;

use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Models\ApprovalRequestStep;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit when a step becomes active with assignees (or none: HR queue). */
final class ApprovalStepActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $assigneeUserIds
     */
    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly ApprovalRequestStep $step,
        public readonly array $assigneeUserIds,
        public readonly bool $isFirst,
    ) {}
}
