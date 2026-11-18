<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AssigneeOrigin: string
{
    use TranslatableEnum;

    case Rule = 'rule';
    case ApplicantChoice = 'applicant_choice';
    case Reassigned = 'reassigned';

    public static function translationKey(): string
    {
        return 'approval.assignee_origin';
    }
}
