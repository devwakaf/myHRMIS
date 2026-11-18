<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

/**
 * PCB/MTD set (Table 1 + reliefs + rules) for one year of assessment.
 * Money parameters are decimal strings.
 */
final class MtdTable
{
    /**
     * @param  list<MtdBand>  $bands
     */
    public function __construct(
        public readonly int $rateSetId,
        public readonly string $code,
        public readonly int $taxYear,
        public readonly array $bands,
        public readonly string $reliefIndividual,
        public readonly string $reliefSpouse,
        public readonly string $reliefDisabledSelf,
        public readonly string $reliefDisabledSpouse,
        public readonly string $childBasic,
        public readonly string $childHigherEd,
        public readonly string $childDisabled,
        public readonly string $childDisabledHigherEd,
        public readonly string $epfReliefCap,
        public readonly string $pcbMinimum,
        public readonly string $additionalPcbMinimum,
        public readonly string $nonResidentRate,
        public readonly bool $autoSocsoEisRelief,
        public readonly string $socsoEisReliefCap,
    ) {}

    public function band(string $p): ?MtdBand
    {
        foreach ($this->bands as $band) {
            if ($band->contains($p)) {
                return $band;
            }
        }

        return null;
    }
}
