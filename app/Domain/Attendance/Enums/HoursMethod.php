<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum HoursMethod: string
{
    use TranslatableEnum;

    case FirstLast = 'first_last';
    case Paired = 'paired';

    public static function translationKey(): string
    {
        return 'attendance.enums.hours_method';
    }
}
