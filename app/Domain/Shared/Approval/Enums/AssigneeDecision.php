<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AssigneeDecision: string
{
    use TranslatableEnum;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NotRequired = 'not_required';
    case Removed = 'removed';

    public static function translationKey(): string
    {
        return 'approval.assignee_decision';
    }
}
