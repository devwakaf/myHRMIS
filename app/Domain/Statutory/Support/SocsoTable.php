<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Statutory\Enums\AgeSwitchRule;
use Carbon\CarbonImmutable;

/** PERKESO (Akta 4) + LINDUNG 24 Jam set: params and verbatim bands. */
final class SocsoTable
{
    /**
     * @param  list<Band>  $bands  columns cat1_employer, cat1_employee, cat1_skbbk_employee, cat2_employer, cat2_skbbk_employee
     */
    public function __construct(
        public readonly int $rateSetId,
        public readonly string $code,
        public readonly string $wageCeiling,
        public readonly int $cat1MaxAge,
        public readonly int $firstInsuredCat2Age,
        public readonly AgeSwitchRule $ageSwitchRule,
        public readonly ?CarbonImmutable $skbbkStart,
        public readonly array $bands,
    ) {}
}
