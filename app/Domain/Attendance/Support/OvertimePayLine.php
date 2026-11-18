<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Approved OT for one (claim, salary component) — spec-03 §5.2 plus spec-04
 * §4.2.1 deltas 3 and 6 (claimNumber, dates, claimMonth).
 */
final class OvertimePayLine
{
    /**
     * @param  array<string, int>  $dayTypeBreakdown  day type => paid OT minutes
     * @param  list<string>  $dates  Y-m-d
     */
    public function __construct(
        public readonly int $claimId,
        public readonly string $claimNumber,
        public readonly CarbonImmutable $claimMonth,
        public readonly ?int $salaryComponentId,
        public readonly int $minutes,
        public readonly Money $amount,
        public readonly array $dayTypeBreakdown,
        public readonly array $dates,
    ) {}
}
