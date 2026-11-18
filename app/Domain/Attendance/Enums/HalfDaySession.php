<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum HalfDaySession: string
{
    use TranslatableEnum;

    case Am = 'am';
    case Pm = 'pm';

    public static function translationKey(): string
    {
        return 'attendance.enums.half_day_session';
    }
}
