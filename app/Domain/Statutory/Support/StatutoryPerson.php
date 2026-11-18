<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\Lindung24Status;
use Carbon\CarbonImmutable;

/**
 * The employee facts the statutory calculators need for one pay period
 * (built from Core's StatutoryProfile; pure data so calculators stay DB-free).
 */
final class StatutoryPerson
{
    public function __construct(
        public readonly CitizenshipStatus $citizenship,
        public readonly CarbonImmutable $dateOfBirth,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly bool $kwspExempt = false,
        public readonly bool $eisExempt = false,
        public readonly ?CarbonImmutable $workPassExpiry = null,
        public readonly ?int $firstInsuredAge = null,
        public readonly Lindung24Status $lindung24 = Lindung24Status::OptedIn,
    ) {}

    public function isForeign(): bool
    {
        return in_array($this->citizenship, [CitizenshipStatus::Foreign, CitizenshipStatus::ForeignPre1998], true);
    }
}
