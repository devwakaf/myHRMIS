<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Money\Rounding;
use App\Domain\Shared\Support\Enums\TranslatableEnum;

/**
 * Per-component rounding (spec-04 §2.1). Every mode is applied through the
 * shared Rounding service (spec-00 A8).
 */
enum RoundingMode: string
{
    use TranslatableEnum;

    case HalfUp2dp = 'half_up_2dp';
    case NearestRinggit = 'nearest_ringgit';
    case CeilRinggit = 'ceil_ringgit';
    case FloorRinggit = 'floor_ringgit';
    case Nearest5sen = 'nearest_5sen';
    case Ceil5sen = 'ceil_5sen';

    public static function translationKey(): string
    {
        return 'payroll.enums.rounding_mode';
    }

    /** Round a decimal string; the result always has two decimals. */
    public function apply(string $value): string
    {
        return (string) match ($this) {
            self::HalfUp2dp => Rounding::halfUp($value, 2),
            self::NearestRinggit => Rounding::toStep($value, '1', \RoundingMode::HalfAwayFromZero, 2),
            self::CeilRinggit => Rounding::toStep($value, '1', \RoundingMode::PositiveInfinity, 2),
            self::FloorRinggit => Rounding::toStep($value, '1', \RoundingMode::NegativeInfinity, 2),
            self::Nearest5sen => Rounding::toStep($value, '0.05', \RoundingMode::HalfAwayFromZero, 2),
            self::Ceil5sen => Rounding::toStep($value, '0.05', \RoundingMode::PositiveInfinity, 2),
        };
    }
}
