<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Payroll\Enums\RoundingMode;
use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Support\HrdcTable;

/**
 * HRD Corp levy (spec-04 §4.6 5f; stat §5, MEDIUM): employer-only, on the
 * hrdc_wage base of Malaysians when the Organisasi is registered. Rate = the
 * Organisasi's levy rate (Teras) or the rule for its headcount; rounding from the rule.
 */
final class HrdcLevyCalculator
{
    /**
     * @return array{applies: bool, wage: string, rate: string, levy: string, rate_set_id: int|null}
     */
    public function calculate(?HrdcTable $table, bool $registered, ?string $organisationRate, int $headcount, string $citizenship, string $wage): array
    {
        $rule = $table?->ruleFor($headcount);
        $applies = $registered && $rule !== null && in_array($citizenship, $rule['base_citizenships'], true);
        $wage = Num::max($wage, '0');

        if (! $applies || $rule === null) {
            return ['applies' => false, 'wage' => '0.00', 'rate' => '0', 'levy' => '0.00', 'rate_set_id' => $table?->rateSetId];
        }

        $rate = $organisationRate !== null && Num::isPositive($organisationRate) ? $organisationRate : $rule['rate'];
        $mode = RoundingMode::tryFrom($rule['rounding_mode']) ?? RoundingMode::HalfUp2dp;

        return [
            'applies' => true,
            'wage' => Num::money($wage),
            'rate' => Num::trim($rate),
            'levy' => $mode->apply(Num::percent($wage, $rate)),
            'rate_set_id' => $table?->rateSetId,
        ];
    }
}
