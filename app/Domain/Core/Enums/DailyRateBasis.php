<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum DailyRateBasis: string
{
    use TranslatableEnum;

    case FixedDivisor = 'fixed_divisor';
    case CalendarDays = 'calendar_days';
    case WorkingDays = 'working_days';

    public static function translationKey(): string
    {
        return 'core.enums.daily_rate_basis';
    }
}
