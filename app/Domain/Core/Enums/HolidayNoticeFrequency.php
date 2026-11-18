<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum HolidayNoticeFrequency: string
{
    use TranslatableEnum;

    case Off = 'off';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public static function translationKey(): string
    {
        return 'core.enums.holiday_notice';
    }
}
