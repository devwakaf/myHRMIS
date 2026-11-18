<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum RestDaySource: string
{
    use TranslatableEnum;

    case Roster = 'roster';
    case Manual = 'manual';

    public static function translationKey(): string
    {
        return 'attendance.enums.rest_day_source';
    }
}
