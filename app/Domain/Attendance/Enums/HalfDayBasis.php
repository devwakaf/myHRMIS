<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum HalfDayBasis: string
{
    use TranslatableEnum;

    case Worked = 'worked';
    case Leave = 'leave';
    case Request = 'request';

    public static function translationKey(): string
    {
        return 'attendance.enums.half_day_basis';
    }
}
