<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ApplicantCancelRule: string
{
    use TranslatableEnum;

    case BeforeStart = 'before_start';
    case Never = 'never';

    public static function translationKey(): string
    {
        return 'leave.enums.applicant_cancel_rule';
    }
}
