<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Shared\Money\Rounding;

/**
 * Decimal day arithmetic at scale 3 (DECIMAL(8,3) columns). Strings in, strings out;
 * no floats anywhere in the leave domain.
 */
final class Dec
{
    public const SCALE = 3;

    public static function add(string|int|null ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, self::s($v), self::SCALE);
        }

        return $sum;
    }

    public static function sub(string|int|null $a, string|int|null $b): string
    {
        return bcsub(self::s($a), self::s($b), self::SCALE);
    }

    public static function mul(string|int|null $a, string|int|null $b, int $scale = self::SCALE): string
    {
        return (string) Rounding::halfUp(bcmul(self::s($a), self::s($b), 12), $scale);
    }

    public static function div(string|int|null $a, string|int|null $b, int $scale = 12): string
    {
        return bcdiv(self::s($a), self::s($b), $scale);
    }

    public static function neg(string|int|null $a): string
    {
        return bcsub('0', self::s($a), self::SCALE);
    }

    public static function cmp(string|int|null $a, string|int|null $b): int
    {
        return bccomp(self::s($a), self::s($b), 12);
    }

    public static function isPositive(string|int|null $a): bool
    {
        return self::cmp($a, '0') > 0;
    }

    public static function isNegative(string|int|null $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function isZero(string|int|null $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function min(string|int|null $a, string|int|null $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::norm($a) : self::norm($b);
    }

    public static function max(string|int|null $a, string|int|null $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::norm($a) : self::norm($b);
    }

    public static function abs(string|int|null $a): string
    {
        return self::isNegative($a) ? self::neg($a) : self::norm($a);
    }

    /** Round half-up to 3 dp (normal form for stored day values). */
    public static function norm(string|int|null $a): string
    {
        return (string) Rounding::halfUp(self::s($a), self::SCALE);
    }

    /** Round to a step (0.25/0.5/1) half-up; a null step only normalises to 3 dp. */
    public static function toStep(string|int|null $value, ?string $step): string
    {
        if ($step === null || self::cmp($step, '0') <= 0) {
            return self::norm($value);
        }

        return (string) Rounding::toStep(self::s($value), $step);
    }

    /** Human form without trailing zeros (5.500 → 5.5). */
    public static function short(string|int|null $a): string
    {
        $n = self::norm($a);

        return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
    }

    private static function s(string|int|null $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }

        return is_int($v) ? (string) $v : trim($v);
    }
}
