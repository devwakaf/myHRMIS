<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum DayKind: string
{
    use TranslatableEnum;

    case Working = 'working';
    case HalfWorking = 'half_working';
    case WeeklyOff = 'weekly_off';
    case Holiday = 'holiday';

    public static function translationKey(): string
    {
        return 'leave.enums.day_kind';
    }
}
