<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\ProrationMethod;
use App\Domain\Leave\Enums\TierBasis;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicyRow;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Dec;
use App\Domain\Leave\Support\Entitlement;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Entitlement for a leave period (spec-02 §3.6): window W, segments split at
 * service-tier anniversaries and policy-assignment boundaries, joining-gap
 * pro-rata per the type's method, half-up rounding to the type's step and the
 * per-period ceiling.
 */
final class EntitlementCalculator
{
    /** Whole months in [from, to] (16/03 → 31/12 = 9). */
    public static function completedMonths(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $m = 0;
        while ($from->addMonthsNoOverflow($m + 1)->subDay()->lessThanOrEqualTo($to)) {
            $m++;
        }

        return $m;
    }

    /** Completed months of service on a date (anniversary-based). */
    public static function serviceMonthsOn(CarbonImmutable $serviceStart, CarbonImmutable $date): int
    {
        if ($date->lessThan($serviceStart)) {
            return 0;
        }

        $m = 0;
        while ($serviceStart->addMonthsNoOverflow($m + 1)->lessThanOrEqualTo($date)) {
            $m++;
        }

        return $m;
    }

    /**
     * Single-regime entitlement for one policy row over W = [from, to] (defaults:
     * period start or joining, period end).
     */
    public function forRow(Employee $employee, LeavePolicyRow $row, LeavePeriod $period, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?ProrationMethod $proration = null): Entitlement
    {
        $from ??= $period->from_date->max($employee->date_of_joining);
        $to ??= $period->to_date;

        return $this->calculate($employee, $row->leaveType, $period, [['from' => $from, 'to' => $to, 'row' => $row]], $proration);
    }

    /**
     * @param  list<array{from: CarbonImmutable, to: CarbonImmutable, row: LeavePolicyRow|null}>  $pieces  contiguous regimes inside the period
     */
    public function calculate(Employee $employee, LeaveType $type, LeavePeriod $period, array $pieces, ?ProrationMethod $proration = null): Entitlement
    {
        if ($pieces === []) {
            throw new InvalidArgumentException('At least one regime is required.');
        }

        $window = new DateRange($pieces[0]['from'], $pieces[array_key_last($pieces)]['to']);
        $windowDays = (string) $window->days();
        $fraction = $this->joiningFraction($window, $period, $proration ?? $type->proration_method);
        $serviceStart = $employee->serviceStart();

        $segments = [];
        foreach ($pieces as $piece) {
            $row = $piece['row'];
            $range = new DateRange($piece['from'], $piece['to']);

            if ($row === null) {
                $segments[] = ['from' => $range->from, 'to' => $range->to ?? $range->from, 'days' => '0', 'row' => null];

                continue;
            }

            $row->loadMissing('tiers');
            $basis = $row->uses_service_tiers ? $row->tier_basis : null;

            if ($basis === TierBasis::ServiceAtPeriodStart || $basis === TierBasis::ServiceAtPeriodEnd) {
                $at = $basis === TierBasis::ServiceAtPeriodStart ? $window->from : $period->to_date;
                $segments[] = ['from' => $range->from, 'to' => $range->to ?? $range->from, 'days' => $row->daysForServiceMonths(self::serviceMonthsOn($serviceStart, $at)), 'row' => $row];

                continue;
            }

            $boundaries = array_map(fn (int $months) => $serviceStart->addMonthsNoOverflow($months), $row->tierThresholds());
            foreach ($range->splitAt($boundaries) as $part) {
                $segments[] = [
                    'from' => $part->from,
                    'to' => $part->to ?? $part->from,
                    'days' => $row->daysForServiceMonths(self::serviceMonthsOn($serviceStart, $part->from)),
                    'row' => $row,
                ];
            }
        }

        // Merge neighbours with the same amount and row (no real boundary between them).
        $merged = [];
        foreach ($segments as $segment) {
            $last = $merged === [] ? null : $merged[array_key_last($merged)];
            if ($last !== null && $last['row'] === $segment['row'] && Dec::cmp($last['days'], $segment['days']) === 0) {
                $merged[array_key_last($merged)]['to'] = $segment['to'];

                continue;
            }
            $merged[] = $segment;
        }

        $values = [];
        $sum = '0';
        foreach ($merged as $i => $segment) {
            $segDays = (string) ((int) $segment['from']->diffInDays($segment['to']) + 1);
            $value = bcdiv(bcmul(bcmul($segment['days'], $segDays, 12), $fraction, 12), $windowDays, 12);
            $values[$i] = $value;
            $merged[$i]['calendar_days'] = (int) $segDays;
            $merged[$i]['value'] = $value;
            $sum = bcadd($sum, $value, 12);
        }

        $total = $this->finish($sum, $type);
        $initial = $this->finish(bcmul($merged[0]['days'], $fraction, 12), $type);

        $boundaries = [];
        $before = '0';
        $daysBefore = 0;
        foreach ($merged as $i => $segment) {
            if ($i > 0) {
                $rest = bcdiv(bcmul(bcmul($segment['days'], (string) ((int) $windowDays - $daysBefore), 12), $fraction, 12), $windowDays, 12);
                $boundaries[] = ['date' => $segment['from'], 'cumulative' => $this->finish(bcadd($before, $rest, 12), $type)];
            }
            $before = bcadd($before, $values[$i], 12);
            $daysBefore += $segment['calendar_days'];
        }

        return new Entitlement($window, $fraction, $merged, $total, $initial, $boundaries);
    }

    /** Joining-gap fraction of W relative to the period (§3.6 step 3). */
    public function joiningFraction(DateRange $window, LeavePeriod $period, ProrationMethod $method): string
    {
        $windowDays = $window->days();
        $periodDays = $period->days();
        if ($windowDays >= $periodDays) {
            return '1';
        }

        return match ($method) {
            ProrationMethod::None => '1',
            ProrationMethod::Days => bcdiv((string) $windowDays, (string) $periodDays, 12),
            ProrationMethod::CompletedMonths => bcdiv(
                (string) self::completedMonths($window->from, $window->to ?? $window->from),
                (string) max(1, self::completedMonths($period->from_date, $period->to_date)),
                12,
            ),
        };
    }

    /** Round to the type's step (half-up) and apply the per-period ceiling. */
    public function finish(string $value, LeaveType $type): string
    {
        $rounded = Dec::toStep($value, $type->rounding_step === null ? null : (string) $type->rounding_step);
        if ($type->max_days_per_period !== null) {
            $rounded = Dec::min($rounded, (string) $type->max_days_per_period);
        }

        return Dec::max($rounded, '0');
    }
}
