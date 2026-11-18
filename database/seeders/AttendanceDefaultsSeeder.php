<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Attendance\Actions\SeedAttendanceDefaults;
use App\Domain\Core\Models\Organisation;
use Illuminate\Database\Seeder;

/**
 * Kehadiran defaults (spec-03 §3.1, §4.12) for organisations that existed before M3.
 * New organisations get them through OrganisationCreated.
 * Idempotent: `php artisan db:seed --class=AttendanceDefaultsSeeder`.
 */
class AttendanceDefaultsSeeder extends Seeder
{
    public function run(SeedAttendanceDefaults $seed): void
    {
        foreach (Organisation::query()->pluck('id') as $id) {
            $seed->handle((int) $id);
        }
    }
}
