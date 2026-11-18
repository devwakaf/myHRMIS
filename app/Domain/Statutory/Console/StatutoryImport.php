<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Console;

use App\Domain\Shared\Support\DomainError;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Services\RateCsvImporter;
use Illuminate\Console\Command;

/**
 * php artisan statutory:import {scheme} {csv} --set= --effective-from= --source-url= [--table=]
 * Creates/updates a DRAFT rate set from a CSV keyed verbatim from the official table (spec-04 §7.1).
 */
final class StatutoryImport extends Command
{
    protected $signature = 'statutory:import {scheme : epf|socso|eis|mtd|tax_reliefs|hrdc|minimum_wage} {csv : path to the CSV file}
        {--set= : rate set code, e.g. EPF-2025-10} {--effective-from= : Y-m-d (new sets only)} {--source-url=} {--table= : child table (defaults to the band table)}';

    protected $description = 'Import a statutory rate table CSV into a draft rate set';

    public function handle(RateCsvImporter $importer): int
    {
        $scheme = RateScheme::tryFrom((string) $this->argument('scheme'));
        $path = (string) $this->argument('csv');
        $code = (string) $this->option('set');
        if ($scheme === null || $code === '' || ! is_file($path)) {
            $this->error('Usage: statutory:import {scheme} {csv} --set=CODE [--effective-from=Y-m-d]');

            return self::FAILURE;
        }

        try {
            $result = $importer->import(
                $scheme, (string) file_get_contents($path), $code,
                $this->option('effective-from') === null ? null : (string) $this->option('effective-from'),
                $this->option('source-url') === null ? null : (string) $this->option('source-url'),
                $this->option('table') === null ? null : (string) $this->option('table'),
            );
        } catch (DomainError $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $this->info("{$result['rows']} rows imported into draft set {$result['set']->code}.");

        return self::SUCCESS;
    }
}
