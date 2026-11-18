<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Actions\SeedPayrollDefaults;
use Illuminate\Database\Seeder;

/**
 * Payroll defaults (spec-04 §7.3) for organisations created before M4, plus the
 * global statutory reference data. Idempotent. New organisations get the
 * defaults through the OrganisationCreated listener.
 */
final class PayrollDefaultsSeeder extends Seeder
{
    public function run(SeedPayrollDefaults $defaults): void
    {
        $this->call(StatutoryRatesSeeder::class);

        foreach (Organisation::query()->orderBy('id')->pluck('id') as $id) {
            $defaults->handle((int) $id);
        }
    }
}
