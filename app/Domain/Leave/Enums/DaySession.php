<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum DaySession: string
{
    use TranslatableEnum;

    case Full = 'full';
    case Am = 'am';
    case Pm = 'pm';

    public static function translationKey(): string
    {
        return 'leave.enums.day_session';
    }
}
