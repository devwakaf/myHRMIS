<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

/** TP1 relief codes and tax-exempt allowance caps of one YA (null cap = no cap). */
final class ReliefTable
{
    /**
     * @param  array<string, string|null>  $tp1Caps
     * @param  array<string, string|null>  $exemptionCaps
     */
    public function __construct(
        public readonly int $rateSetId,
        public readonly array $tp1Caps,
        public readonly array $exemptionCaps,
    ) {}
}
