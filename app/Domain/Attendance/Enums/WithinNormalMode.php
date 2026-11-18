<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum WithinNormalMode: string
{
    use TranslatableEnum;

    case None = 'none';
    case OrpHalfOrFull = 'orp_half_or_full';
    case OrpDays = 'orp_days';

    public static function translationKey(): string
    {
        return 'attendance.enums.within_normal_mode';
    }
}
