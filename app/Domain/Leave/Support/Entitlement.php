<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Leave\Models\LeavePolicyRow;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;

/**
 * Result of EntitlementCalculator (spec-02 §3.6).
 */
final class Entitlement
{
    /**
     * @param  list<array{from: CarbonImmutable, to: CarbonImmutable, days: string, row: LeavePolicyRow|null, calendar_days: int, value: string}>  $segments
     * @param  string  $total  E: rounded, capped entitlement for W
     * @param  string  $initial  first regime applied to the whole W (credited at generation)
     * @param  list<array{date: CarbonImmutable, cumulative: string}>  $boundaries  E through each later regime
     */
    public function __construct(
        public readonly DateRange $window,
        public readonly string $fraction,
        public readonly array $segments,
        public readonly string $total,
        public readonly string $initial,
        public readonly array $boundaries,
    ) {}
}
