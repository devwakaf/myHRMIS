<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Console;

use App\Domain\Statutory\Actions\RateSetActions;
use App\Domain\Statutory\Enums\RateSetStatus;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\StatutoryFixtures;
use Illuminate\Console\Command;

/**
 * php artisan statutory:verify {code} — runs the fixture suite; a passing DRAFT
 * set is published as `unverified` (a second Super Admin then presses "Sahkan").
 * For an already published set it only reports the fixture results.
 */
final class StatutoryVerify extends Command
{
    protected $signature = 'statutory:verify {code : rate set code}';

    protected $description = 'Run the statutory fixture suite and publish a passing draft rate set';

    public function handle(RateSetActions $actions, StatutoryFixtures $fixtures): int
    {
        $set = StatutoryRateSet::query()->where('code', (string) $this->argument('code'))->first();
        if ($set === null) {
            $this->error('Rate set not found.');

            return self::FAILURE;
        }

        $failures = $set->status === RateSetStatus::Draft ? $actions->publish($set) : $fixtures->run($set);
        foreach ($failures as $failure) {
            $this->error('FAIL '.$failure);
        }
        if ($failures !== []) {
            return self::FAILURE;
        }

        $this->info("Fixtures passed for {$set->code} (status: {$set->refresh()->status->value}).");

        return self::SUCCESS;
    }
}
