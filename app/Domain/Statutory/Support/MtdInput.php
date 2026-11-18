<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

/**
 * Variables of the LHDN computerised MTD method (spec-04 §4.6 5e; stat §4).
 * All amounts are unrounded decimal strings. Prior-month totals (Y, K, X, Z, LP)
 * already include TP3 and opening balances.
 */
final class MtdInput
{
    public function __construct(
        public readonly int $category,
        public readonly bool $resident,
        public readonly int $month,
        public readonly string $y,
        public readonly string $k,
        public readonly string $x,
        public readonly string $z,
        public readonly string $lp,
        public readonly string $y1,
        public readonly string $k1,
        public readonly string $yt,
        public readonly string $kt,
        public readonly string $lp1,
        public readonly ?string $y2 = null,
        public readonly bool $disabledSelf = false,
        public readonly bool $disabledSpouse = false,
        public readonly bool $claimsChildRelief = false,
        public readonly int $childrenBasic = 0,
        public readonly int $childrenHigherEd = 0,
        public readonly int $childrenDisabled = 0,
        public readonly int $childrenDisabledHigherEd = 0,
        public readonly string $zakatPayroll = '0',
        public readonly string $zakatExternal = '0',
        public readonly string $socsoEisEmployee = '0',
        public readonly string $socsoEisPrior = '0',
    ) {}

    /** Months remaining after the current one. */
    public function n(): int
    {
        return 12 - $this->month;
    }
}
