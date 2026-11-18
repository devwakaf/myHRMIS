<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ScheduleDayKind: string
{
    use TranslatableEnum;

    case Shift = 'shift';
    case Rest = 'rest';
    case Off = 'off';

    public static function translationKey(): string
    {
        return 'attendance.enums.schedule_day_kind';
    }
}
