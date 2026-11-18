<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum SinglePunchPolicy: string
{
    use TranslatableEnum;

    case Review = 'review';
    case Absent = 'absent';
    case HalfDay = 'half_day';
    case Present = 'present';

    public static function translationKey(): string
    {
        return 'attendance.enums.single_punch_policy';
    }
}
