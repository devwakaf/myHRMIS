<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Enums\EpfPart;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Support\Band;
use Illuminate\Support\Facades\DB;

/**
 * Fixture suite run by `statutory:verify` / "Jalankan Ujian Fixture" and in CI
 * (spec-04 §7.1): known published rows (stat findings §8) plus structural checks
 * (contiguous bands, no gaps up to the ceiling/limit, non-decreasing amounts).
 * Returns the list of failures (empty = pass).
 */
final class StatutoryFixtures
{
    public function __construct(private readonly StatutoryRateRepository $rates) {}

    /**
     * @return list<string>
     */
    public function run(StatutoryRateSet $set): array
    {
        $this->rates->flush();

        return match ($set->scheme) {
            RateScheme::Epf => $this->epf($set),
            RateScheme::Socso => $this->socso($set),
            RateScheme::Eis => $this->eis($set),
            RateScheme::Mtd => $this->mtd($set),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function epf(StatutoryRateSet $set): array
    {
        $table = $this->rates->epf($set);
        $fails = [];
        foreach ([['A', '3250.00', '424.00', '359.00'], ['A', '6710.80', '816.00', '748.00'], ['E', '3250.00', '131.00', '0.00'], ['C', '3250.00', '212.00', '180.00']] as [$part, $wage, $er, $ee]) {
            $band = $table->band(EpfPart::from($part), $wage);
            if ($band === null || Num::cmp($band->amounts['employer'] ?? '-1', $er) !== 0 || Num::cmp($band->amounts['employee'] ?? '-1', $ee) !== 0) {
                $fails[] = "KWSP {$part} RM{$wage} → {$er}/{$ee}";
            }
        }
        foreach (['A', 'C', 'E'] as $part) {
            $limit = '20000.00';
            foreach ($table->rules as $rule) {
                if ($rule->part->value === $part && $rule->tableLimit !== null) {
                    $limit = $rule->tableLimit;
                }
            }
            // The employer share legitimately drops where the rate falls (RM5,000.01), so only the employee share must not decrease.
            $fails = [...$fails, ...$this->structure("KWSP {$part}", $table->bands[$part] ?? [], $limit, ['employer', 'employee'], ['employee'])];
        }

        return $fails;
    }

    /**
     * @return list<string>
     */
    private function socso(StatutoryRateSet $set): array
    {
        $table = $this->rates->socso($set);
        $fails = [];
        $band = Band::find($table->bands, '5950.00');
        if ($band === null || ($band->amounts['cat1_employer'] ?? null) !== '104.15' || ($band->amounts['cat1_skbbk_employee'] ?? null) !== '44.65'
            || ($band->amounts['cat2_employer'] ?? null) !== '74.40') {
            $fails[] = 'PERKESO RM5,950 → 104.15 / SKBBK 44.65 / kat. 2 74.40';
        }
        $atCeiling = Band::find($table->bands, Num::min('7000.00', $table->wageCeiling));
        if ($atCeiling === null || $band === null || $atCeiling->from !== $band->from) {
            $fails[] = 'PERKESO RM7,000 → baris siling';
        }

        return [...$fails, ...$this->structure('PERKESO', $table->bands, $table->wageCeiling, ['cat1_employer', 'cat1_employee', 'cat1_skbbk_employee', 'cat2_employer', 'cat2_skbbk_employee'])];
    }

    /**
     * @return list<string>
     */
    private function eis(StatutoryRateSet $set): array
    {
        $table = $this->rates->eis($set);
        $fails = [];
        foreach ([['5950.00', '11.90'], ['5050.00', '10.10']] as [$wage, $amount]) {
            $band = Band::find($table->bands, $wage);
            if ($band === null || ($band->amounts['employer'] ?? null) !== $amount || ($band->amounts['employee'] ?? null) !== $amount) {
                $fails[] = "SIP RM{$wage} → {$amount}/{$amount}";
            }
        }

        return [...$fails, ...$this->structure('SIP', $table->bands, $table->wageCeiling, ['employer', 'employee'])];
    }

    /**
     * @return list<string>
     */
    private function mtd(StatutoryRateSet $set): array
    {
        $table = $this->rates->mtd($set);
        $fails = [];
        $previous = null;
        foreach ($table->bands as $band) {
            if ($previous !== null && Num::cmp($band->pAbove, $previous) !== 0) {
                $fails[] = "Jadual 1: jurang selepas {$previous}";
            }
            $previous = $band->pUpto;
        }
        if ($previous !== null) {
            $fails[] = 'Jadual 1: baris terakhir mesti terbuka';
        }
        $band = $table->band('67529.60');
        $t = $band === null ? null : Num::add(Num::percent(Num::sub('67529.60', $band->m), $band->r), $band->b(1));
        if ($t === null || Num::cmp($t, '3428.256') !== 0) {
            $fails[] = 'PCB P = 67,529.60 → T = 3,428.256';
        }

        return $fails;
    }

    /**
     * @param  list<Band>  $bands
     * @param  list<string>  $columns
     * @param  list<string>|null  $monotonic  columns that must not decrease (default: all)
     * @return list<string>
     */
    private function structure(string $label, array $bands, string $upTo, array $columns, ?array $monotonic = null): array
    {
        if ($bands === []) {
            return ["{$label}: tiada jalur"];
        }
        $fails = [];
        $previous = null;
        foreach ($bands as $band) {
            if ($previous !== null) {
                if (Num::cmp($band->from, Num::add($previous->to, '0.01')) !== 0) {
                    $fails[] = "{$label}: jalur tidak bersambung selepas {$previous->to}";
                }
                foreach ($monotonic ?? $columns as $column) {
                    $a = $previous->amounts[$column] ?? null;
                    $b = $band->amounts[$column] ?? null;
                    if ($a !== null && $b !== null && Num::cmp($b, $a) < 0) {
                        $fails[] = "{$label}: {$column} menurun pada {$band->from}";
                    }
                }
            }
            foreach ($columns as $column) {
                if (($band->amounts[$column] ?? null) === null) {
                    $fails[] = "{$label}: {$column} kosong pada {$band->from}";
                }
            }
            $previous = $band;
        }
        if (Num::cmp($bands[0]->from, '0.01') > 0) {
            $fails[] = "{$label}: jalur pertama bermula pada {$bands[0]->from}";
        }
        if ($previous !== null && Num::cmp($previous->to, $upTo) < 0) {
            $fails[] = "{$label}: jalur berakhir pada {$previous->to}, sebelum {$upTo}";
        }

        return array_values(array_unique($fails));
    }

    /** Count of band rows of a set (UI). */
    public function bandCount(StatutoryRateSet $set): int
    {
        return (int) DB::table(RateTableCatalogue::bandTable($set->scheme))->where('rate_set_id', $set->id)->count();
    }
}
