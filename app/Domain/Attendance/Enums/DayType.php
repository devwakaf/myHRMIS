<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum DayType: string
{
    use TranslatableEnum;

    case Normal = 'normal';
    case RestDay = 'rest_day';
    case PublicHoliday = 'public_holiday';

    public static function translationKey(): string
    {
        return 'attendance.enums.day_type';
    }
}
