<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Console;

use App\Domain\Shared\Support\DomainError;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\PlaceholderBands;
use Illuminate\Console\Command;

/**
 * php artisan statutory:placeholder {code} — DEVELOPMENT ONLY: fills missing
 * bands of a KWSP / PERKESO / SIP set with generated placeholder rows so a demo
 * can run. Refused in production; the set is flagged and can never be verified.
 */
final class StatutoryPlaceholder extends Command
{
    protected $signature = 'statutory:placeholder {code : rate set code}';

    protected $description = 'DEV ONLY: generate placeholder bands for an unverified rate set';

    public function handle(PlaceholderBands $bands): int
    {
        $set = StatutoryRateSet::query()->where('code', (string) $this->argument('code'))->first();
        if ($set === null) {
            $this->error('Rate set not found.');

            return self::FAILURE;
        }

        try {
            $count = $bands->generate($set);
        } catch (DomainError $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }
        $this->warn("{$count} PLACEHOLDER rows added to {$set->code}. Not official — replace with the published table before production.");

        return self::SUCCESS;
    }
}
