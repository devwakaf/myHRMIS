<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\PublicHoliday;
use App\Domain\Shared\Authorization\RoleService;
use Illuminate\Database\Seeder;

/**
 * Global reference data (A10): permissions, banks, public holiday catalogue.
 *
 * The 2026 holiday dates below are DEMO data (UNVERIFIED-STAT): lunar-calendar
 * dates are estimates. Replace them with the official federal/state gazette
 * before production (spec-00 B1.13, open Q6).
 */
final class ReferenceDataSeeder extends Seeder
{
    public function run(RoleService $roles): void
    {
        $roles->ensurePermissions();
        $this->banks();
        $this->publicHolidays2026();
    }

    private function banks(): void
    {
        $banks = [
            ['MBB', 'Malayan Banking Berhad (Maybank)', 'MBBEMYKL'],
            ['CIMB', 'CIMB Bank Berhad', 'CIBBMYKL'],
            ['PBB', 'Public Bank Berhad', 'PBBEMYKL'],
            ['RHB', 'RHB Bank Berhad', 'RHBBMYKL'],
            ['HLB', 'Hong Leong Bank Berhad', 'HLBBMYKL'],
            ['AMB', 'AmBank (M) Berhad', 'ARBKMYKL'],
            ['BIMB', 'Bank Islam Malaysia Berhad', 'BIMBMYKL'],
            ['BKRM', 'Bank Kerjasama Rakyat Malaysia Berhad', 'BKRMMYKL'],
            ['BSN', 'Bank Simpanan Nasional', 'BSNAMYK1'],
            ['AFB', 'Affin Bank Berhad', 'PHBMMYKL'],
            ['ABB', 'Alliance Bank Malaysia Berhad', 'MFBBMYKL'],
            ['BMMB', 'Bank Muamalat Malaysia Berhad', 'BMMBMYKL'],
            ['AGRO', 'Agrobank', 'AGOBMYKL'],
            ['OCBC', 'OCBC Bank (Malaysia) Berhad', 'OCBCMYKL'],
            ['UOB', 'United Overseas Bank (Malaysia) Bhd', 'UOVBMYKL'],
            ['HSBC', 'HSBC Bank Malaysia Berhad', 'HBMBMYKL'],
            ['SCB', 'Standard Chartered Bank Malaysia Berhad', 'SCBLMYKX'],
            ['MBSB', 'MBSB Bank Berhad', 'AFBQMYKL'],
        ];

        foreach ($banks as [$code, $name, $swift]) {
            Bank::query()->updateOrCreate(['code' => $code], ['name' => $name, 'swift_code' => $swift, 'is_active' => true]);
        }
    }

    private function publicHolidays2026(): void
    {
        $ref = 'DEMO 2026 — sahkan dengan warta rasmi (UNVERIFIED)';
        $rows = [
            // [date, scope, name_ms, name_en, mandatory]
            ['2026-02-17', 'federal', 'Tahun Baharu Cina', 'Chinese New Year', false],
            ['2026-02-18', 'federal', 'Tahun Baharu Cina (Hari Kedua)', 'Chinese New Year (Second Day)', false],
            ['2026-03-21', 'federal', 'Hari Raya Aidilfitri', 'Hari Raya Aidilfitri', false],
            ['2026-03-22', 'federal', 'Hari Raya Aidilfitri (Hari Kedua)', 'Hari Raya Aidilfitri (Second Day)', false],
            ['2026-05-01', 'federal', 'Hari Pekerja', 'Labour Day', true],
            ['2026-05-27', 'federal', 'Hari Raya Haji', 'Hari Raya Haji', false],
            ['2026-05-31', 'federal', 'Hari Wesak', 'Wesak Day', false],
            ['2026-06-01', 'federal', 'Hari Keputeraan Yang di-Pertuan Agong', 'Birthday of the Yang di-Pertuan Agong', true],
            ['2026-06-17', 'federal', 'Awal Muharram', 'Awal Muharram', false],
            ['2026-08-26', 'federal', 'Maulidur Rasul', 'Prophet Muhammad\'s Birthday', false],
            ['2026-08-31', 'federal', 'Hari Kebangsaan', 'National Day', true],
            ['2026-09-16', 'federal', 'Hari Malaysia', 'Malaysia Day', true],
            ['2026-11-08', 'federal', 'Deepavali', 'Deepavali', false],
            ['2026-12-25', 'federal', 'Hari Krismas', 'Christmas Day', false],
            // Pahang (state)
            ['2026-05-07', 'PHG', 'Hari Hol Pahang', 'Hari Hol of Pahang', false],
            ['2026-07-30', 'PHG', 'Hari Keputeraan Sultan Pahang', 'Birthday of the Sultan of Pahang', true],
            ['2026-03-07', 'PHG', 'Nuzul Al-Quran', 'Nuzul Al-Quran', false],
            // Selangor (state)
            ['2026-01-01', 'SGR', 'Tahun Baharu', 'New Year\'s Day', false],
            ['2026-02-01', 'SGR', 'Thaipusam', 'Thaipusam', false],
            ['2026-03-07', 'SGR', 'Nuzul Al-Quran', 'Nuzul Al-Quran', false],
            ['2026-12-11', 'SGR', 'Hari Keputeraan Sultan Selangor', 'Birthday of the Sultan of Selangor', true],
        ];

        foreach ($rows as [$date, $scope, $ms, $en, $mandatory]) {
            PublicHoliday::query()->updateOrCreate(
                ['date' => $date, 'scope' => $scope, 'name_ms' => $ms],
                ['year' => (int) substr($date, 0, 4), 'name_en' => $en, 'is_mandatory' => $mandatory, 'source_ref' => $ref],
            );
        }
    }
}
