<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum NoticeEnforcement: string
{
    use TranslatableEnum;

    case Warn = 'warn';
    case Block = 'block';

    public static function translationKey(): string
    {
        return 'leave.enums.notice_enforcement';
    }
}
