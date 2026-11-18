<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Payroll\Models\BankFileFormat;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Enums\RateSetStatus;
use App\Domain\Statutory\Models\StatutoryFileFormat;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\RateCsvImporter;
use App\Domain\Statutory\Services\RateTableCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Statutory reference data (spec-04 §7.2), global and idempotent. Values come from
 * findings-statutory-my (sources in each set's source_url). Band tables that must
 * be keyed verbatim from the official PDFs are seeded with the fixture rows only
 * (database/seeders/data/statutory/*.csv) and stay `unverified`; import the full
 * tables with `php artisan statutory:import` before production. Nothing here is a
 * code constant used by the calculators.
 */
final class StatutoryRatesSeeder extends Seeder
{
    private const DATA = __DIR__.'/data/statutory/';

    public function run(): void
    {
        $epf = $this->set(RateScheme::Epf, 'EPF-2025-10', '2025-10-01', RateSetStatus::Unverified,
            'https://www.kwsp.gov.my/documents/d/guest/third_schedule_from_-1-october-2025', 'Jadual Ketiga KWSP (Oktober 2025)');
        self::epfRules($epf->id);
        self::epfParams($epf->id);
        $this->bands($epf, 'epf_wage_bands', 'epf_wage_bands.csv');

        $socso = $this->set(RateScheme::Socso, 'SOCSO-2026-06', '2026-06-01', RateSetStatus::Unverified,
            'https://www.perkeso.gov.my/images/lindung/lindung-24-jam/JadualCarumanBaharuTermasukSKBBK.pdf', 'Jadual Caruman Baharu Termasuk SKBBK');
        self::socsoParams($socso->id);
        $this->bands($socso, 'socso_bands', 'socso_bands.csv');

        $eis = $this->set(RateScheme::Eis, 'EIS-2024-10', '2024-10-01', RateSetStatus::Unverified,
            'https://perkeso.gov.my/images/akta/ACT%20800/AKTA%20A1725%20(BM)_AKTA%20SISTEM%20INSURANS%20PEKERJAAN%20(PINDAAN)%202024.pdf', 'Akta A1725 (2024)');
        self::eisParams($eis->id);
        $this->bands($eis, 'eis_bands', 'eis_bands.csv');

        $mtd = $this->set(RateScheme::Mtd, 'MTD-YA2026', '2026-01-01', RateSetStatus::Verified,
            'https://www.hasil.gov.my/wp-content/uploads/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf', 'Spesifikasi Kaedah Pengiraan Berkomputer PCB 2026');
        self::mtdTable($mtd->id);

        $reliefs = $this->set(RateScheme::TaxReliefs, 'RELIEF-YA2026', '2026-01-01', RateSetStatus::Unverified,
            'https://www.hasil.gov.my/wp-content/uploads/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf', 'TP1 dan elaun dikecualikan YA2026');
        self::reliefRows($reliefs->id);

        $hrdc = $this->set(RateScheme::Hrdc, 'HRDC-2026', '2026-01-01', RateSetStatus::Unverified,
            'https://hrdcorp.gov.my', 'Levi HRD Corp (MEDIUM)');
        self::hrdcRules($hrdc->id);

        $minimum = $this->set(RateScheme::MinimumWage, 'MINWAGE-2025-08', '2025-08-01', RateSetStatus::Verified,
            'https://jtksm.mohr.gov.my', 'Perintah Gaji Minimum P.U.(A) 376/2024');
        self::minimumWageRow($minimum->id);

        self::fileFormats();
        self::bankFormats();
    }

    private function set(RateScheme $scheme, string $code, string $from, RateSetStatus $status, string $url, string $ref): StatutoryRateSet
    {
        $set = StatutoryRateSet::query()->where('code', $code)->first();
        if ($set !== null) {
            return $set;
        }
        $set = new StatutoryRateSet;
        $set->forceFill([
            'scheme' => $scheme, 'code' => $code, 'name' => $ref, 'effective_from' => $from, 'status' => $status,
            'source_url' => $url, 'source_ref' => $ref, 'published_at' => now(),
            'verified_at' => $status === RateSetStatus::Verified ? now() : null,
            'notes' => $status === RateSetStatus::Verified ? 'Disahkan daripada sumber rasmi (HIGH) semasa seed.' : 'UNVERIFIED — sahkan dengan sumber rasmi sebelum production.',
        ])->save();

        return $set;
    }

    private function bands(StatutoryRateSet $set, string $table, string $file): void
    {
        if (DB::table($table)->where('rate_set_id', $set->id)->exists()) {
            return;
        }
        $rows = app(RateCsvImporter::class)->parse((string) file_get_contents(self::DATA.$file), RateTableCatalogue::COLUMNS[$table]);
        DB::table($table)->insert(array_map(fn (array $r) => ['rate_set_id' => $set->id] + $r, $rows));
    }

    /** KWSP rules per stat §1.1 (rates in percent; rounding variants UNVERIFIED-STAT). */
    public static function epfRules(int $setId, string $percentRounding = 'ceil_each_share'): void
    {
        if (DB::table('epf_rate_rules')->where('rate_set_id', $setId)->exists()) {
            return;
        }
        $common = ['rate_set_id' => $setId, 'percent_rounding' => $percentRounding, 'bonus_rule_base' => 'band_upper', 'created_at' => now(), 'updated_at' => now()];
        DB::table('epf_rate_rules')->insert([
            $common + ['part' => 'A', 'citizenship_group' => 'citizen', 'age_min' => 0, 'age_max' => 60, 'wage_threshold' => '5000.00', 'employer_rate_low' => '13', 'employer_rate_high' => '12', 'employee_rate' => '11', 'table_limit' => '20000.00', 'bonus_rule' => true, 'uses_table' => true],
            $common + ['part' => 'E', 'citizenship_group' => 'citizen', 'age_min' => 60, 'age_max' => null, 'wage_threshold' => null, 'employer_rate_low' => '4', 'employer_rate_high' => '4', 'employee_rate' => '0', 'table_limit' => '20000.00', 'bonus_rule' => false, 'uses_table' => true],
            $common + ['part' => 'A', 'citizenship_group' => 'pr_or_pre1998', 'age_min' => 0, 'age_max' => 60, 'wage_threshold' => '5000.00', 'employer_rate_low' => '13', 'employer_rate_high' => '12', 'employee_rate' => '11', 'table_limit' => '20000.00', 'bonus_rule' => true, 'uses_table' => true],
            $common + ['part' => 'C', 'citizenship_group' => 'pr_or_pre1998', 'age_min' => 60, 'age_max' => null, 'wage_threshold' => '5000.00', 'employer_rate_low' => '6.5', 'employer_rate_high' => '6', 'employee_rate' => '5.5', 'table_limit' => '20000.00', 'bonus_rule' => true, 'uses_table' => true],
            $common + ['part' => 'F', 'citizenship_group' => 'foreign', 'age_min' => 0, 'age_max' => 75, 'wage_threshold' => null, 'employer_rate_low' => '2', 'employer_rate_high' => '2', 'employee_rate' => '2', 'table_limit' => null, 'bonus_rule' => false, 'uses_table' => false],
        ]);
    }

    public static function epfParams(int $setId): void
    {
        DB::table('epf_params')->insertOrIgnore([
            'rate_set_id' => $setId, 'min_age' => 14, 'max_age' => 75, 'age_switch_rule' => 'month_after_birthday',
            'work_pass_stop_months' => 2, 'due_day' => 15, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function socsoParams(int $setId, array $overrides = []): void
    {
        DB::table('socso_params')->insertOrIgnore($overrides + [
            'rate_set_id' => $setId, 'wage_ceiling' => '6000.00', 'cat1_max_age' => 60, 'first_insured_cat2_age' => 55,
            'age_switch_rule' => 'month_after_birthday', 'skbbk_start' => '2026-06-01', 'skbbk_local_voluntary_from' => '2026-07-08',
            'skbbk_optout_window_from' => '2026-07-13', 'skbbk_optout_window_to' => '2026-08-31', 'due_day' => 15, 'interest_rate_pa' => '6.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function eisParams(int $setId): void
    {
        DB::table('eis_params')->insertOrIgnore([
            'rate_set_id' => $setId, 'wage_ceiling' => '6000.00', 'min_age' => 18, 'max_age' => 60, 'first_insured_exclusion_age' => 57,
            'eligible_citizenships' => json_encode(['citizen', 'permanent_resident']), 'age_switch_rule' => 'month_after_birthday',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** LHDN Table 1 YA2026 (HIGH) + reliefs and rules (stat §4.2–§4.5). */
    public static function mtdTable(int $setId): void
    {
        if (! DB::table('mtd_tax_bands')->where('rate_set_id', $setId)->exists()) {
            $rows = app(RateCsvImporter::class)->parse((string) file_get_contents(self::DATA.'mtd_tax_bands_ya2026.csv'), RateTableCatalogue::COLUMNS['mtd_tax_bands']);
            DB::table('mtd_tax_bands')->insert(array_map(fn (array $r) => ['rate_set_id' => $setId] + $r, $rows));
        }
        DB::table('mtd_parameters')->insertOrIgnore([
            'rate_set_id' => $setId, 'tax_year' => 2026, 'relief_individual' => '9000', 'relief_spouse' => '4000', 'relief_disabled_self' => '7000',
            'relief_disabled_spouse' => '6000', 'child_basic' => '2000', 'child_higher_ed' => '8000', 'child_disabled' => '8000',
            'child_disabled_higher_ed' => '16000', 'epf_relief_cap' => '4000', 'voluntary_epf_cap_no_mandatory' => '7000', 'pcb_minimum' => '10',
            'additional_pcb_minimum' => '10', 'non_resident_rate' => '30', 'resident_min_days' => 182, 'auto_socso_eis_relief' => false,
            'socso_eis_relief_cap' => '350', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** TP1 relief codes and exempt-allowance caps of YA2026 (UNVERIFIED-STAT; names in our own BM wording). */
    public static function reliefRows(int $setId): void
    {
        if (! DB::table('tp1_relief_codes')->where('rate_set_id', $setId)->exists()) {
            $tp1 = [
                ['parents_medical', 'Perubatan ibu bapa', 'Parents\' medical expenses', '8000'],
                ['supporting_equipment', 'Peralatan sokongan (OKU)', 'Supporting equipment', '6000'],
                ['self_education', 'Pendidikan sendiri', 'Self education', '7000'],
                ['serious_disease', 'Penyakit serius', 'Serious disease', '10000'],
                ['socso_eis', 'Caruman PERKESO dan SIP', 'PERKESO and SIP contributions', '350'],
                ['lifestyle', 'Gaya hidup', 'Lifestyle', '2500'],
                ['sspn', 'Simpanan SSPN', 'SSPN savings', null],
                ['prs_annuity', 'PRS / anuiti tertangguh', 'PRS / deferred annuity', '3000'],
                ['childcare', 'Yuran taska / tadika', 'Childcare fees', '3000'],
                ['housing_loan_interest', 'Faedah pinjaman perumahan', 'Housing loan interest', null],
                ['ev_composting_cctv', 'Pengecas EV / kompos / CCTV', 'EV charging / composting / CCTV', '2500'],
                ['tourism_cultural', 'Pelancongan / kebudayaan', 'Tourism / cultural', '1000'],
            ];
            DB::table('tp1_relief_codes')->insert(array_map(fn ($r) => ['rate_set_id' => $setId, 'code' => $r[0], 'name_ms' => $r[1], 'name_en' => $r[2], 'annual_cap' => $r[3]], $tp1));
        }
        if (! DB::table('tax_exemption_caps')->where('rate_set_id', $setId)->exists()) {
            $caps = [
                ['travel_petrol_toll', 'Perjalanan / petrol / tol', 'Travel / petrol / toll', '6000'],
                ['childcare_allowance', 'Elaun penjagaan anak', 'Childcare allowance', '3000'],
                ['phone_broadband', 'Telefon dan jalur lebar', 'Phone and broadband', null],
                ['award', 'Anugerah', 'Award', '2000'],
                ['parking', 'Tempat letak kereta', 'Parking', null],
                ['meal', 'Elaun makan', 'Meal allowance', null],
            ];
            DB::table('tax_exemption_caps')->insert(array_map(fn ($r) => ['rate_set_id' => $setId, 'code' => $r[0], 'name_ms' => $r[1], 'name_en' => $r[2], 'annual_cap' => $r[3]], $caps));
        }
    }

    /** HRD Corp (MEDIUM, UNVERIFIED-STAT): ≥10 Malaysians 1% mandatory; 5–9 0.5% optional. */
    public static function hrdcRules(int $setId): void
    {
        if (DB::table('hrdc_levy_rules')->where('rate_set_id', $setId)->exists()) {
            return;
        }
        $base = json_encode(['citizen', 'permanent_resident']);
        DB::table('hrdc_levy_rules')->insert([
            ['rate_set_id' => $setId, 'min_headcount' => 10, 'max_headcount' => null, 'rate' => '1', 'mandatory' => true, 'base_citizenships' => $base, 'rounding_mode' => 'half_up_2dp'],
            ['rate_set_id' => $setId, 'min_headcount' => 5, 'max_headcount' => 9, 'rate' => '0.5', 'mandatory' => false, 'base_citizenships' => $base, 'rounding_mode' => 'half_up_2dp'],
        ]);
    }

    public static function minimumWageRow(int $setId): void
    {
        if (DB::table('minimum_wages')->where('rate_set_id', $setId)->exists()) {
            return;
        }
        DB::table('minimum_wages')->insert(['rate_set_id' => $setId, 'monthly' => '1700', 'hourly' => '8.72', 'daily_6day' => '65.38', 'daily_5day' => '78.46', 'daily_4day' => '98.08', 'scope' => 'all']);
    }

    /** PERKESO v2.1 (verified, HIGH), CP39 (decimal style unverified), KWSP (placeholder, unverified). */
    public static function fileFormats(): void
    {
        $sen = fn (string $name, int $start, int $length, string $source) => ['name' => $name, 'start' => $start, 'length' => $length, 'source' => $source, 'format' => 'sen', 'align' => 'right'];
        $formats = [
            [
                'scheme' => 'perkeso', 'code' => 'PERKESO-ASSIST-2.1', 'name' => 'Fail teks gabungan PERKESO (ASSIST 2.0)', 'version' => '2.1',
                'effective_from' => '2026-02-13', 'record_type' => 'fixed', 'status' => 'verified',
                'source_url' => 'https://perkeso.gov.my/images/borang/lindung24jam/28072026-formatteksfail.pdf',
                'filename_pattern' => 'PERKESO_{employer}_{MMYYYY}.txt',
                'layout' => [
                    'record_length' => 278,
                    // UNVERIFIED-STAT options: numeric pad, month semantics (wage month), wage before the ceiling.
                    'options' => ['numeric_pad' => '0', 'month' => 'wage', 'wage_basis' => 'before_ceiling'],
                    'fields' => [
                        ['name' => 'employer_code', 'start' => 1, 'length' => 12, 'source' => 'org.perkeso_employer_code'],
                        ['name' => 'mycoid', 'start' => 13, 'length' => 20, 'source' => 'org.ssm_no'],
                        ['name' => 'id', 'start' => 33, 'length' => 12, 'source' => 'employee.perkeso_id'],
                        ['name' => 'name', 'start' => 45, 'length' => 150, 'source' => 'employee.full_name', 'format' => 'upper'],
                        ['name' => 'month', 'start' => 195, 'length' => 6, 'source' => 'slip.period_mmyyyy'],
                        $sen('wage', 201, 14, 'statutory.socso_wage'),
                        $sen('socso_employer', 215, 6, 'statutory.socso_employer'),
                        $sen('socso_employee', 221, 6, 'statutory.socso_employee'),
                        $sen('eis_employer', 227, 6, 'statutory.eis_employer'),
                        $sen('eis_employee', 233, 6, 'statutory.eis_employee'),
                        $sen('skbbk_employee', 239, 6, 'statutory.skbbk_employee'),
                        ['name' => 'filler_1', 'start' => 245, 'length' => 14, 'source' => 'filler'],
                        ['name' => 'filler_2', 'start' => 259, 'length' => 20, 'source' => 'filler'],
                    ],
                ],
            ],
            [
                'scheme' => 'cp39', 'code' => 'CP39-2026', 'name' => 'CP39 (LHDN)', 'version' => '2026', 'effective_from' => '2026-01-01',
                'record_type' => 'fixed', 'status' => 'unverified',
                'source_url' => 'https://www.hasil.gov.my/wp-content/uploads/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf',
                'filename_pattern' => null,
                // Header 57 / detail 136 positions are built in StatutoryFiles::cp39 (stat §4.8). UNVERIFIED: amount style, TIN form.
                'layout' => ['header_length' => 57, 'detail_length' => 136, 'options' => ['amount_style' => 'literal', 'tin_mode' => 'digits_only']],
            ],
            [
                'scheme' => 'kwsp', 'code' => 'KWSP-PLACEHOLDER', 'name' => 'Fail caruman KWSP (susun atur belum disahkan)', 'version' => '0',
                'effective_from' => '2025-10-01', 'record_type' => 'csv', 'status' => 'unverified',
                'source_url' => 'https://www.kwsp.gov.my/en/employer/responsibilities/mandatory-contribution',
                'filename_pattern' => 'KWSP_{kwsp_employer}_{CMM}{CYYYY}.csv',
                'layout' => [
                    'delimiter' => ',',
                    'header_rows' => [['member_no', 'ic_no', 'name', 'wage', 'employer', 'employee', 'contribution_month']],
                    'fields' => [
                        ['name' => 'member_no', 'source' => 'employee.kwsp_id'],
                        ['name' => 'ic_no', 'source' => 'employee.ic_no'],
                        ['name' => 'name', 'source' => 'employee.full_name', 'format' => 'upper'],
                        ['name' => 'wage', 'source' => 'statutory.epf_wage', 'format' => 'amount'],
                        ['name' => 'employer', 'source' => 'statutory.epf_employer', 'format' => 'amount'],
                        ['name' => 'employee', 'source' => 'statutory.epf_employee', 'format' => 'amount'],
                        ['name' => 'contribution_month', 'source' => 'slip.contribution_mmyyyy'],
                    ],
                ],
            ],
        ];

        foreach ($formats as $format) {
            if (StatutoryFileFormat::query()->where('code', $format['code'])->exists()) {
                continue;
            }
            $row = new StatutoryFileFormat;
            $row->forceFill($format + ['line_ending' => "\r\n", 'encoding' => 'ASCII'])->save();
        }
    }

    /** Only "CSV Generik" — bank-specific templates are user-supplied configuration (open Q10). */
    public static function bankFormats(): void
    {
        if (BankFileFormat::query()->where('code', 'CSV_GENERIK')->exists()) {
            return;
        }
        $row = new BankFileFormat;
        $row->forceFill([
            'code' => 'CSV_GENERIK', 'name' => 'CSV Generik', 'version' => '1', 'file_type' => 'csv', 'status' => 'unverified',
            'layout' => [
                'type' => 'csv', 'delimiter' => ',',
                'header_rows' => [['account_no', 'account_name', 'ic_no', 'amount', 'payment_date', 'bank_code']],
                'fields' => [
                    ['name' => 'account_no', 'source' => 'employee.bank_account_no'],
                    ['name' => 'account_name', 'source' => 'employee.bank_account_name'],
                    ['name' => 'ic_no', 'source' => 'employee.ic_no'],
                    ['name' => 'amount', 'source' => 'slip.net_pay', 'format' => 'amount'],
                    ['name' => 'payment_date', 'source' => 'run.payment_date'],
                    ['name' => 'bank_code', 'source' => 'employee.bank_code'],
                ],
                'trailer' => [['TOTAL', 'source:totals.count', '', 'source:totals.amount', '', '']],
            ],
        ])->save();
    }
}
