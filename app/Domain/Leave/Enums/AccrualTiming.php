<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AccrualTiming: string
{
    use TranslatableEnum;

    case SubPeriodStart = 'sub_period_start';
    case SubPeriodEnd = 'sub_period_end';
    case JoiningDay = 'joining_day';

    public static function translationKey(): string
    {
        return 'leave.enums.accrual_timing';
    }
}
