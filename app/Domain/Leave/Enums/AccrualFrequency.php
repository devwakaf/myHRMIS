<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AccrualFrequency: string
{
    use TranslatableEnum;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public static function translationKey(): string
    {
        return 'leave.enums.accrual_frequency';
    }

    /** Length of one sub-period in months. */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }

    public function periodsPerYear(): int
    {
        return intdiv(12, $this->months());
    }
}
