<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Shared\Money\Num;

/** One row of LHDN Table 1: p_above < P <= p_upto. */
final class MtdBand
{
    public function __construct(
        public readonly string $pAbove,
        public readonly ?string $pUpto,
        public readonly string $m,
        public readonly string $r,
        public readonly string $bCat13,
        public readonly string $bCat2,
        public readonly bool $noTax,
    ) {}

    public function contains(string $p): bool
    {
        return Num::cmp($p, $this->pAbove) > 0 && ($this->pUpto === null || Num::cmp($p, $this->pUpto) <= 0);
    }

    public function b(int $category): string
    {
        return $category === 2 ? $this->bCat2 : $this->bCat13;
    }
}
