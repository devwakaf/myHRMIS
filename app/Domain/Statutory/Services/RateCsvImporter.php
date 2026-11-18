<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Services;

use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Enums\RateSetStatus;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Models\StatutoryRateSet;
use Illuminate\Support\Facades\DB;

/**
 * "Import CSV" / `statutory:import` (spec-04 §7.1): creates or updates a DRAFT
 * rate set and replaces one child table from a CSV keyed verbatim from the
 * official table. Placeholder cells such as "<PDF>" are refused.
 */
final class RateCsvImporter
{
    /**
     * @return array{set: StatutoryRateSet, rows: int}
     */
    public function import(RateScheme $scheme, string $csv, string $setCode, ?string $effectiveFrom = null, ?string $sourceUrl = null, ?string $table = null): array
    {
        $table ??= RateTableCatalogue::bandTable($scheme);
        if (! in_array($table, RateTableCatalogue::tablesFor($scheme), true) || ! isset(RateTableCatalogue::COLUMNS[$table])) {
            throw new StatutoryRateException('statutory.errors.import_table', ['table' => $table]);
        }

        $rows = $this->parse($csv, RateTableCatalogue::COLUMNS[$table]);

        return DB::transaction(function () use ($scheme, $setCode, $effectiveFrom, $sourceUrl, $table, $rows): array {
            $set = StatutoryRateSet::query()->where('code', $setCode)->first();
            if ($set === null) {
                if ($effectiveFrom === null) {
                    throw new StatutoryRateException('statutory.errors.import_effective_from');
                }
                $set = new StatutoryRateSet(['scheme' => $scheme, 'code' => $setCode, 'effective_from' => $effectiveFrom, 'source_url' => $sourceUrl]);
                $set->save();
            } elseif ($set->status !== RateSetStatus::Draft) {
                throw new StatutoryRateException('statutory.errors.set_not_draft', ['code' => $setCode]);
            } elseif ($set->scheme !== $scheme) {
                throw new StatutoryRateException('statutory.errors.scheme_mismatch', ['code' => $setCode]);
            }
            if ($sourceUrl !== null) {
                $set->forceFill(['source_url' => $sourceUrl])->save();
            }

            DB::table($table)->where('rate_set_id', $set->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert(array_map(fn (array $r) => ['rate_set_id' => $set->id] + $r, $chunk));
            }

            return ['set' => $set, 'rows' => count($rows)];
        });
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, string|null>>
     */
    public function parse(string $csv, array $columns): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $l) => trim($l) !== '' && ! str_starts_with(trim($l), '#')));
        if ($lines === []) {
            throw new StatutoryRateException('statutory.errors.import_empty');
        }

        $header = array_map('trim', str_getcsv(array_shift($lines)));
        $missing = array_diff(array_filter($columns, fn ($c) => ! in_array($c, RateTableCatalogue::NULLABLE, true)), $header);
        if ($missing !== []) {
            throw new StatutoryRateException('statutory.errors.import_columns', ['columns' => implode(', ', $missing)]);
        }

        $rows = [];
        foreach ($lines as $n => $line) {
            $cells = array_map('trim', str_getcsv($line));
            $row = [];
            foreach ($columns as $column) {
                $index = array_search($column, $header, true);
                $value = $index === false ? '' : (string) ($cells[$index] ?? '');
                if (str_contains($value, '<') || str_contains($value, '>')) {
                    throw new StatutoryRateException('statutory.errors.import_placeholder', ['line' => $n + 2, 'column' => $column]);
                }
                if ($value === '') {
                    if (! in_array($column, RateTableCatalogue::NULLABLE, true)) {
                        throw new StatutoryRateException('statutory.errors.import_blank', ['line' => $n + 2, 'column' => $column]);
                    }
                    $row[$column] = null;

                    continue;
                }
                $row[$column] = $value;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
