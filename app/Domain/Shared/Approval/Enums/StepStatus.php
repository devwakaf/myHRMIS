<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum StepStatus: string
{
    use TranslatableEnum;

    case Waiting = 'waiting';
    case Active = 'active';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public static function translationKey(): string
    {
        return 'approval.step_status';
    }
}
