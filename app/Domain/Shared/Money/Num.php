<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use DivisionByZeroError;

/**
 * Decimal arithmetic on numeric strings at a fixed working scale (10), used by
 * the Payroll and Statutory engines (spec-04 §3.1, §4.6: intermediate values are
 * never rounded). Rounding itself always goes through Rounding (A8).
 */
final class Num
{
    public const SCALE = 10;

    public static function of(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $s = is_int($value) ? (string) $value : trim($value);

        return is_numeric($s) ? $s : '0';
    }

    public static function add(string|int|null ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, self::of($v), self::SCALE);
        }

        return $sum;
    }

    public static function sub(string|int|null $a, string|int|null $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string|int|null $a, string|int|null $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(string|int|null $a, string|int|null $b): string
    {
        if (self::isZero($b)) {
            throw new DivisionByZeroError('Division by zero.');
        }

        return bcdiv(self::of($a), self::of($b), self::SCALE);
    }

    /** $rate is a percentage ("11" = 11%). */
    public static function percent(string|int|null $amount, string|int|null $rate): string
    {
        return bcdiv(bcmul(self::of($amount), self::of($rate), self::SCALE), '100', self::SCALE);
    }

    public static function cmp(string|int|null $a, string|int|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isZero(string|int|null $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isPositive(string|int|null $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function isNegative(string|int|null $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function min(string|int|null ...$values): string
    {
        $best = null;
        foreach ($values as $v) {
            if ($best === null || self::cmp($v, $best) < 0) {
                $best = self::of($v);
            }
        }

        return $best ?? '0';
    }

    public static function max(string|int|null ...$values): string
    {
        $best = null;
        foreach ($values as $v) {
            if ($best === null || self::cmp($v, $best) > 0) {
                $best = self::of($v);
            }
        }

        return $best ?? '0';
    }

    public static function clamp(string|int|null $value, string|int $low, string|int $high): string
    {
        return self::min(self::max($value, $low), $high);
    }

    public static function neg(string|int|null $a): string
    {
        return bcsub('0', self::of($a), self::SCALE);
    }

    /** Half-up to sen, as a "0.00" string. */
    public static function money(string|int|null $a): string
    {
        return (string) Rounding::halfUp(self::of($a), 2);
    }

    /** Fixed-scale string (no rounding beyond truncation of excess working digits). */
    public static function fixed(string|int|null $a, int $scale): string
    {
        return (string) Rounding::halfUp(self::of($a), $scale);
    }

    /** Strip trailing zeros for display/trace ("285.6880000000" → "285.688"). */
    public static function trim(string|int|null $a): string
    {
        $s = self::of($a);
        if (! str_contains($s, '.')) {
            return $s;
        }
        $s = rtrim(rtrim($s, '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }

    /** Amount in sen as an integer string ("3250.00" → "325000"). */
    public static function sen(string|int|null $a): string
    {
        return bcmul(self::money($a), '100', 0);
    }
}
