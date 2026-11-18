<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Actions;

use App\Domain\Shared\Dating\EffectiveDatedSeries;
use App\Domain\Statutory\Enums\RateSetStatus;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\RateTableCatalogue;
use App\Domain\Statutory\Services\StatutoryFixtures;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kadar Statutori lifecycle (spec-04 §1.1, §7.1): Draf → (fixtures pass,
 * "Terbitkan") Belum Disahkan → ("Sahkan", second Super Admin) Disahkan.
 * Publishing closes the predecessor at D − 1 (A9). Only drafts are editable.
 */
final class RateSetActions
{
    public function __construct(
        private readonly StatutoryFixtures $fixtures,
        private readonly EffectiveDatedSeries $series,
    ) {}

    /** "Salin Set Baharu": a draft copy of every table of the set. */
    public function copy(StatutoryRateSet $source, string $code, CarbonImmutable $effectiveFrom, ?string $sourceUrl = null): StatutoryRateSet
    {
        return DB::transaction(function () use ($source, $code, $effectiveFrom, $sourceUrl): StatutoryRateSet {
            $copy = new StatutoryRateSet([
                'scheme' => $source->scheme, 'code' => $code, 'name' => $source->name, 'effective_from' => $effectiveFrom->toDateString(),
                'source_url' => $sourceUrl ?? $source->source_url, 'source_ref' => $source->source_ref, 'notes' => $source->notes,
            ]);
            $copy->save();
            foreach (RateTableCatalogue::tablesFor($source->scheme) as $table) {
                $rows = DB::table($table)->where('rate_set_id', $source->id)->get();
                foreach ($rows as $row) {
                    $data = (array) $row;
                    unset($data['id']);
                    $data['rate_set_id'] = $copy->id;
                    foreach (['created_at', 'updated_at'] as $ts) {
                        if (array_key_exists($ts, $data)) {
                            $data[$ts] = now();
                        }
                    }
                    DB::table($table)->insert($data);
                }
            }

            return $copy;
        });
    }

    /**
     * "Jalankan Ujian Fixture" + "Terbitkan": draft → unverified.
     *
     * @return list<string> fixture failures (empty when published)
     */
    public function publish(StatutoryRateSet $set, bool $force = false): array
    {
        if ($set->status !== RateSetStatus::Draft) {
            throw new StatutoryRateException('statutory.errors.set_not_draft', ['code' => $set->code]);
        }
        $failures = $this->fixtures->run($set);
        if ($failures !== [] && ! $force) {
            return $failures;
        }

        DB::transaction(function () use ($set, $failures): void {
            $set->forceFill([
                'status' => RateSetStatus::Unverified,
                'fixture_passed_at' => $failures === [] ? now() : null,
                'published_at' => now(),
            ]);
            $this->series->insert($set);
        });

        return $failures;
    }

    /** "Sahkan": a second Super Admin confirms the set against the official source. */
    public function verify(StatutoryRateSet $set, User $actor): StatutoryRateSet
    {
        if ($set->status !== RateSetStatus::Unverified) {
            throw new StatutoryRateException('statutory.errors.verify_status');
        }
        if ($set->created_by !== null && $set->created_by === $actor->id) {
            throw new StatutoryRateException('statutory.errors.verifier_is_creator');
        }
        if ($set->is_placeholder) {
            throw new StatutoryRateException('statutory.errors.placeholder_cannot_verify');
        }
        $set->forceFill(['status' => RateSetStatus::Verified, 'verified_at' => now(), 'verified_by' => $actor->id])->save();

        return $set;
    }

    public function delete(StatutoryRateSet $set): void
    {
        if ($set->status !== RateSetStatus::Draft) {
            throw new StatutoryRateException('statutory.errors.set_not_draft', ['code' => $set->code]);
        }
        $set->delete();
    }
}
