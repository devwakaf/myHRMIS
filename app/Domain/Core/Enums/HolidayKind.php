<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum HolidayKind: string
{
    use TranslatableEnum;

    case PublicHoliday = 'public';
    case State = 'state';
    case Replacement = 'replacement';
    case WeeklyOff = 'weekly_off';
    case Organisation = 'organisation';
    case AdHoc = 'ad_hoc';

    public static function translationKey(): string
    {
        return 'core.enums.holiday_kind';
    }
}
