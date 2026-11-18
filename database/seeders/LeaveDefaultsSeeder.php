<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Actions\SeedLeaveDefaults;
use Illuminate\Database\Seeder;

/**
 * Cuti defaults (spec-02 §6.4) for organisations that existed before M2.
 * New organisations get them automatically through OrganisationCreated.
 * Idempotent: `php artisan db:seed --class=LeaveDefaultsSeeder`.
 */
class LeaveDefaultsSeeder extends Seeder
{
    public function run(SeedLeaveDefaults $seed): void
    {
        foreach (Organisation::query()->pluck('id') as $id) {
            $seed->handle((int) $id);
        }
    }
}
