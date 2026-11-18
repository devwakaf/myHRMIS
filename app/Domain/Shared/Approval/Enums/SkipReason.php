<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum SkipReason: string
{
    use TranslatableEnum;

    case ConditionFalse = 'condition_false';
    case ApplicantOnly = 'applicant_only';
    case SameAsPrevious = 'same_as_previous';
    case NoApprover = 'no_approver';

    public static function translationKey(): string
    {
        return 'approval.skip_reasons';
    }
}
