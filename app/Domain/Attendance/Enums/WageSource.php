<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum WageSource: string
{
    use TranslatableEnum;

    case Payroll = 'payroll';
    case Manual = 'manual';

    public static function translationKey(): string
    {
        return 'attendance.enums.wage_source';
    }
}
