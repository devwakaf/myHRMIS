<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Statutory\Enums\AgeSwitchRule;

/** SIP (Akta 800) set: params and bands. */
final class EisTable
{
    /**
     * @param  list<string>  $eligibleCitizenships
     * @param  list<Band>  $bands  columns employer, employee
     */
    public function __construct(
        public readonly int $rateSetId,
        public readonly string $code,
        public readonly string $wageCeiling,
        public readonly int $minAge,
        public readonly int $maxAge,
        public readonly int $firstInsuredExclusionAge,
        public readonly array $eligibleCitizenships,
        public readonly AgeSwitchRule $ageSwitchRule,
        public readonly array $bands,
    ) {}
}
