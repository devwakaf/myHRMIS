<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AttachmentRule: string
{
    use TranslatableEnum;

    case Never = 'never';
    case Always = 'always';
    case OverDays = 'over_days';

    public static function translationKey(): string
    {
        return 'leave.enums.attachment_rule';
    }
}
