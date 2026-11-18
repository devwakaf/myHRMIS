<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Enums\RateSetStatus;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Models\StatutoryRateSet;
use Illuminate\Support\Facades\DB;

/**
 * DEVELOPMENT-ONLY placeholder bands (spec-04 §7.1 "UNVERIFIED placeholder"
 * generator). They approximate the published tables so a demo can run; they are
 * NOT the official tables, the set is flagged is_placeholder, can never be
 * verified, and `block_unverified_rates` keeps them out of production runs.
 *
 * - KWSP A/C/E: share = ceil(band upper × rate) on the Third Schedule band grid.
 * - PERKESO / SIP: rate × band midpoint rounded up to 5 sen (PERKESO's own
 *   rounding is not uniform — official rows must replace these).
 * Existing rows (e.g. verbatim fixture rows) are kept.
 */
final class PlaceholderBands
{
    public function generate(StatutoryRateSet $set, bool $allowInProduction = false): int
    {
        if (app()->isProduction() && ! $allowInProduction) {
            throw new StatutoryRateException('statutory.errors.placeholder_production');
        }
        if ($set->status === RateSetStatus::Verified) {
            throw new StatutoryRateException('statutory.errors.placeholder_verified');
        }

        $count = match ($set->scheme) {
            RateScheme::Epf => $this->epf($set),
            RateScheme::Socso => $this->midpoint($set, 'socso_bands', ['cat1_employer' => '1.75', 'cat1_employee' => '0.5', 'cat1_skbbk_employee' => '0.75', 'cat2_employer' => '1.25', 'cat2_skbbk_employee' => '0.75']),
            RateScheme::Eis => $this->midpoint($set, 'eis_bands', ['employer_amount' => '0.2', 'employee_amount' => '0.2']),
            default => 0,
        };
        $set->forceFill(['is_placeholder' => true, 'notes' => trim(($set->notes ?? '')."\nPLACEHOLDER: jalur dijana untuk pembangunan sahaja — bukan jadual rasmi.")])->save();

        return $count;
    }

    private function epf(StatutoryRateSet $set): int
    {
        $rules = DB::table('epf_rate_rules')->where('rate_set_id', $set->id)->where('uses_table', true)->get()->groupBy('part');
        $rows = [];
        foreach ($rules as $part => $partRules) {
            $rule = $partRules->first();
            $limit = (string) ($rule->table_limit ?? '20000.00');
            $existing = DB::table('epf_wage_bands')->where('rate_set_id', $set->id)->where('part', $part)->pluck('wage_from')->map(fn ($v) => (string) $v)->all();
            foreach ($this->epfGrid($limit) as [$from, $to]) {
                if (in_array($from, $existing, true)) {
                    continue;
                }
                if (Num::cmp($to, '10.00') <= 0) {
                    $er = $ee = '0.00';
                } else {
                    $threshold = $rule->wage_threshold === null ? null : (string) $rule->wage_threshold;
                    $erRate = $threshold === null || Num::cmp($to, $threshold) <= 0 ? (string) $rule->employer_rate_low : (string) $rule->employer_rate_high;
                    $er = Num::money((string) Rounding::ceilRinggit(Num::percent($to, $erRate)));
                    $ee = Num::money((string) Rounding::ceilRinggit(Num::percent($to, (string) $rule->employee_rate)));
                }
                $rows[] = ['rate_set_id' => $set->id, 'part' => (string) $part, 'wage_from' => $from, 'wage_to' => $to, 'employer_amount' => $er, 'employee_amount' => $ee];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('epf_wage_bands')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Third Schedule grid: ≤10 nil; 10.01–20; RM20 bands to 5,000; RM100 bands to the limit.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function epfGrid(string $limit): array
    {
        $grid = [['0.00', '10.00'], ['10.01', '20.00']];
        for ($upper = 40; $upper <= 5000; $upper += 20) {
            $grid[] = [Num::money(Num::add((string) ($upper - 20), '0.01')), Num::money((string) $upper)];
        }
        for ($upper = 5100; Num::cmp((string) $upper, $limit) <= 0; $upper += 100) {
            $grid[] = [Num::money(Num::add((string) ($upper - 100), '0.01')), Num::money((string) $upper)];
        }

        return $grid;
    }

    /**
     * @param  array<string, string>  $rates  column => percent
     */
    private function midpoint(StatutoryRateSet $set, string $table, array $rates): int
    {
        $ceiling = (string) (DB::table($set->scheme === RateScheme::Socso ? 'socso_params' : 'eis_params')->where('rate_set_id', $set->id)->value('wage_ceiling') ?? '6000.00');
        $existing = DB::table($table)->where('rate_set_id', $set->id)->pluck('wage_from')->map(fn ($v) => (string) $v)->all();
        $grid = [['0.00', '30.00'], ['30.01', '50.00'], ['50.01', '70.00'], ['70.01', '100.00'], ['100.01', '140.00'], ['140.01', '200.00'], ['200.01', '300.00'], ['300.01', '400.00']];
        for ($upper = 500; Num::cmp((string) $upper, $ceiling) <= 0; $upper += 100) {
            $grid[] = [Num::money(Num::add((string) ($upper - 100), '0.01')), Num::money((string) $upper)];
        }

        $rows = [];
        foreach ($grid as [$from, $to]) {
            if (in_array($from, $existing, true)) {
                continue;
            }
            $mid = Num::div(Num::add(Num::max(Num::sub($from, '0.01'), '0'), $to), '2');
            $row = ['rate_set_id' => $set->id, 'wage_from' => $from, 'wage_to' => $to];
            foreach ($rates as $column => $rate) {
                $row[$column] = (string) Rounding::toStep(Num::percent($mid, $rate), '0.05', \RoundingMode::PositiveInfinity, 2);
            }
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        // Seeded rows can carry blank cells (the source did not state them): fill only the blanks.
        $filled = 0;
        foreach (DB::table($table)->where('rate_set_id', $set->id)->get() as $row) {
            $fill = [];
            foreach ($rates as $column => $rate) {
                if ($row->{$column} === null) {
                    $mid = Num::div(Num::add(Num::max(Num::sub((string) $row->wage_from, '0.01'), '0'), (string) $row->wage_to), '2');
                    $fill[$column] = (string) Rounding::toStep(Num::percent($mid, $rate), '0.05', \RoundingMode::PositiveInfinity, 2);
                }
            }
            if ($fill !== []) {
                DB::table($table)->where('id', $row->id)->update($fill);
                $filled++;
            }
        }

        return count($rows) + $filled;
    }
}
