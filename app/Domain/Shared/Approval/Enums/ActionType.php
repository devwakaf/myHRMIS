<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ActionType: string
{
    use TranslatableEnum;

    case Submitted = 'submitted';
    case StepActivated = 'step_activated';
    case StepSkipped = 'step_skipped';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case OverrideApproved = 'override_approved';
    case OverrideRejected = 'override_rejected';
    case Reassigned = 'reassigned';
    case Reminded = 'reminded';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public static function translationKey(): string
    {
        return 'approval.action_types';
    }
}
