<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use BcMath\Number;
use InvalidArgumentException;
use RoundingMode;

/**
 * The only place rounding happens (A8). All methods take and return BcMath\Number.
 */
final class Rounding
{
    /** Half away from zero (1.005 → 1.01, -1.005 → -1.01). */
    public static function halfUp(Number|string|int $value, int $scale = 2): Number
    {
        return self::fix(self::num($value)->round($scale, RoundingMode::HalfAwayFromZero), $scale);
    }

    /** Drop digits beyond $scale (towards zero). */
    public static function truncate(Number|string|int $value, int $scale = 2): Number
    {
        return self::fix(self::num($value)->round($scale, RoundingMode::TowardsZero), $scale);
    }

    /** Away from zero at $scale (1.001 → 1.01, -1.001 → -1.01); formula ROUNDUP (spec-04 §3.3). */
    public static function awayFromZero(Number|string|int $value, int $scale = 0): Number
    {
        return self::fix(self::num($value)->round($scale, RoundingMode::AwayFromZero), $scale);
    }

    /** Truncate to sen, then round up to the next 5 sen (PCB rule). */
    public static function upToFiveSen(Number|string|int $value): Number
    {
        $truncated = self::truncate($value, 2);
        $steps = $truncated->mul(20)->round(0, RoundingMode::PositiveInfinity);

        return self::fix($steps->div(20, 2), 2);
    }

    /** Round up to the next whole ringgit (KWSP percentage method). */
    public static function ceilRinggit(Number|string|int $value): Number
    {
        return self::fix(self::num($value)->round(0, RoundingMode::PositiveInfinity), 0);
    }

    /**
     * Round to a multiple of $step (e.g. 0.5 days) using the given mode.
     */
    public static function toStep(Number|string|int $value, Number|string $step, RoundingMode $mode = RoundingMode::HalfAwayFromZero, int $scale = 3): Number
    {
        $step = self::num($step);
        if ($step->compare(0) <= 0) {
            throw new InvalidArgumentException('Step must be positive.');
        }

        $units = self::num($value)->div($step, 12)->round(0, $mode);

        return self::fix($units->mul($step), $scale);
    }

    private static function num(Number|string|int $value): Number
    {
        return $value instanceof Number ? $value : new Number(is_int($value) ? $value : trim($value));
    }

    /** Normalise the scale so string output always has exactly $scale decimals. */
    private static function fix(Number $value, int $scale): Number
    {
        return $value->add(new Number('0'), $scale);
    }
}
