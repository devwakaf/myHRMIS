<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Statutory\Enums\CitizenshipGroup;
use App\Domain\Statutory\Enums\EpfBonusBase;
use App\Domain\Statutory\Enums\EpfPart;
use App\Domain\Statutory\Enums\EpfPercentRounding;

/** One epf_rate_rules row (rates are percentages). */
final class EpfRule
{
    public function __construct(
        public readonly EpfPart $part,
        public readonly CitizenshipGroup $group,
        public readonly int $ageMin,
        public readonly ?int $ageMax,
        public readonly ?string $wageThreshold,
        public readonly string $employerRateLow,
        public readonly string $employerRateHigh,
        public readonly string $employeeRate,
        public readonly ?string $tableLimit,
        public readonly EpfPercentRounding $percentRounding,
        public readonly bool $bonusRule,
        public readonly EpfBonusBase $bonusBase,
        public readonly bool $usesTable,
    ) {}

    public function matches(CitizenshipGroup $group, int $age): bool
    {
        return $this->group === $group && $age >= $this->ageMin && ($this->ageMax === null || $age < $this->ageMax);
    }
}
