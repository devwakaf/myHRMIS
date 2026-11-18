<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data always; the demo dataset only outside production.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }

        // Cuti defaults for any organisation created before M2 (idempotent).
        $this->call(LeaveDefaultsSeeder::class);

        // Kehadiran defaults for any organisation created before M3 (idempotent).
        $this->call(AttendanceDefaultsSeeder::class);

        // Demo Cuti data: policy assignments and sample applications (demo dataset only).
        if (! app()->isProduction()) {
            $this->call(DemoLeaveSeeder::class);
        }

        // Demo Kehadiran data: locations, shift assignments, check-ins, requests and OT claims (demo dataset only).
        if (! app()->isProduction()) {
            $this->call(DemoAttendanceSeeder::class);
        }

        // Payroll defaults for any organisation created before M4: statutory rate sets (StatutoryRatesSeeder
        // is called inside), components, standard structure, settings (idempotent).
        $this->call(PayrollDefaultsSeeder::class);

        // Demo Payroll data: structures assigned to demo Kakitangan and one sample Proses Gaji through to
        // Dihantar, on PLACEHOLDER statutory rates (demo dataset only).
        if (! app()->isProduction()) {
            $this->call(DemoPayrollSeeder::class);
        }
    }
}
