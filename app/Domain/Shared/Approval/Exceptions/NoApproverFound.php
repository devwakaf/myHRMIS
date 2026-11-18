<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Exceptions;

use App\Domain\Shared\Approval\Models\ApprovalWorkflowStep;
use App\Domain\Shared\Support\DomainError;

final class NoApproverFound extends DomainError
{
    public function __construct(public readonly ApprovalWorkflowStep $step)
    {
        parent::__construct('approval.errors.no_approver_found', [
            'step' => $step->name,
            'sequence' => $step->sequence,
        ]);
    }
}
