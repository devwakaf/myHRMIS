<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Statutory\Enums\SocsoCategory;

/** PERKESO + SKBBK outcome (wage before the ceiling, as the ASSIST file needs it). */
final class SocsoResult
{
    /**
     * @param  array{from: string, to: string}|null  $band
     */
    public function __construct(
        public readonly SocsoCategory $category,
        public readonly string $wage,
        public readonly string $employer,
        public readonly string $employee,
        public readonly string $skbbkEmployee,
        public readonly bool $skbbkApplies,
        public readonly ?int $rateSetId,
        public readonly ?array $band,
    ) {}
}
