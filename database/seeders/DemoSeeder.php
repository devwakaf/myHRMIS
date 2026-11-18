<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Actions\CreateEmployee;
use App\Domain\Core\Actions\CreateOrganisation;
use App\Domain\Core\Actions\GenerateWeeklyOffs;
use App\Domain\Core\Actions\HolidayAssignmentActions;
use App\Domain\Core\Actions\ImportPublicHolidays;
use App\Domain\Core\Actions\SaveDepartmentApprovers;
use App\Domain\Core\Enums\EmploymentCategory;
use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\Enums\Gender;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Core\FakeMyKad;
use Illuminate\Database\Seeder;

/**
 * Demo dataset (A10): two Organisasi (Pahang, Selangor), branches, a 3-level
 * department tree, ~20 + 5 fictional Kakitangan (citizen / PR / foreign),
 * approvers, a 2026 holiday list and the default approval workflows.
 *
 * All names and MyKad numbers are invented (format-valid, fictional).
 *
 * Demo logins (local development only — change or remove before production):
 *   Password for every demo account: Demo@2026
 *   admin@myhrmis.test          Super Admin
 *   hr.manager@myhrmis.test     Pengurus HR (Organisasi Pahang)
 *   hr.exec@myhrmis.test        Eksekutif HR (Organisasi Pahang)
 *   ketua@myhrmis.test          Kakitangan + Ketua Jabatan IT (Pahang)
 *   staff@myhrmis.test          Kakitangan (Pahang)
 *   payroll@myhrmis.test        Pegawai Payroll (Pahang)
 *   gm@myhrmis.test             Kakitangan (Pengurus Besar; Pelulus Jabatan akar)
 *   lead.dev@myhrmis.test       Kakitangan (Pelulus Jabatan Pembangunan Aplikasi)
 *   hr.selangor@myhrmis.test    Pengurus HR (Organisasi Selangor)
 */
final class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo@2026';

    public function __construct(
        private readonly CreateOrganisation $createOrganisation,
        private readonly CreateEmployee $createEmployee,
        private readonly RoleService $roles,
    ) {}

    public function run(): void
    {
        $admin = $this->user('Pentadbir Sistem', 'admin@myhrmis.test');
        $admin->forceFill(['is_super_admin' => true])->save();

        $pahang = $this->organisationPahang();
        $selangor = $this->organisationSelangor();

        $admin->forceFill(['current_organisation_id' => $pahang->id])->save();
    }

    private function organisationPahang(): Organisation
    {
        $org = Organisation::query()->where('short_code', 'SDT')->first() ?? $this->createOrganisation->handle([
            'name' => 'Syarikat Demo Teknologi Sdn. Bhd.',
            'short_code' => 'SDT',
            'registration_type' => 'company',
            'ssm_no' => '202001000001',
            'kwsp_employer_no' => '012345678',
            'perkeso_employer_code' => 'E1100000001',
            'lhdn_employer_no' => '1234567890',
            'lhdn_hq_employer_no' => '1234567890',
            'hrd_corp_registered' => true,
            'hrd_corp_levy_rate' => '1.0000',
            'address_line1' => 'Aras 3, Menara Demo',
            'address_line2' => 'Jalan Teluk Sisek',
            'postcode' => '25000',
            'city' => 'Kuantan',
            'state' => 'PHG',
            'phone' => '09-5550000',
            'email' => 'hr@sdt.example',
            'daily_rate_basis' => 'fixed_divisor',
            'daily_rate_divisor' => '26.00',
            'is_active' => true,
        ]);

        OrganisationContext::run($org, function () use ($org): void {
            if (Employee::query()->exists()) {
                return;
            }

            $hq = Branch::query()->create(['code' => 'KTN', 'name' => 'Ibu Pejabat Kuantan', 'name_en' => 'Kuantan Head Office', 'state' => 'PHG', 'city' => 'Kuantan', 'postcode' => '25000']);
            $tmr = Branch::query()->create(['code' => 'TMR', 'name' => 'Cawangan Temerloh', 'name_en' => 'Temerloh Branch', 'state' => 'PHG', 'city' => 'Temerloh', 'postcode' => '28000']);

            $root = Department::query()->whereNull('parent_id')->firstOrFail();
            $hr = $this->department('HR', 'Pentadbiran & Sumber Manusia', 'Administration & Human Resources', $root, 'CC-100');
            $fin = $this->department('FIN', 'Kewangan', 'Finance', $root, 'CC-200');
            $it = $this->department('IT', 'Teknologi Maklumat', 'Information Technology', $root, 'CC-300');
            $dev = $this->department('DEV', 'Pembangunan Aplikasi', 'Application Development', $it, 'CC-310');
            $inf = $this->department('INF', 'Infrastruktur', 'Infrastructure', $it, 'CC-320');
            $pay = $this->department('PAY', 'Payroll', 'Payroll', $fin, 'CC-210');

            $d = fn (string $code, string $name, string $en) => Designation::query()->create(['code' => $code, 'name' => $name, 'name_en' => $en]);
            $gm = $d('PB', 'Pengurus Besar', 'General Manager');
            $hrm = $d('PSM', 'Pengurus Sumber Manusia', 'Human Resources Manager');
            $hre = $d('ESM', 'Eksekutif Sumber Manusia', 'Human Resources Executive');
            $itm = $d('PIT', 'Pengurus Teknologi Maklumat', 'IT Manager');
            $swe = $d('JP', 'Jurutera Perisian', 'Software Engineer');
            $tech = $d('JT', 'Juruteknik IT', 'IT Technician');
            $acc = $d('AKT', 'Akauntan', 'Accountant');
            $po = $d('PP', 'Pegawai Payroll', 'Payroll Officer');
            $clerk = $d('KA', 'Kerani Akaun', 'Accounts Clerk');
            $admin = $d('PT', 'Pembantu Tadbir', 'Administrative Assistant');

            $g = fn (string $code, string $name, int $rank, string $min, string $max) => Grade::query()->create(['code' => $code, 'name' => $name, 'rank' => $rank, 'salary_min' => $min, 'salary_max' => $max]);
            $g1 = $g('G1', 'Gred 1 (Sokongan)', 10, '1700.00', '2800.00');
            $g2 = $g('G2', 'Gred 2 (Eksekutif Muda)', 20, '2800.00', '4500.00');
            $g3 = $g('G3', 'Gred 3 (Eksekutif Kanan)', 30, '4500.00', '7000.00');
            $g4 = $g('G4', 'Gred 4 (Pengurus)', 40, '7000.00', '12000.00');
            $g5 = $g('G5', 'Gred 5 (Pengurusan Atasan)', 50, '12000.00', '25000.00');

            $permanent = EmploymentType::query()->where('category', EmploymentCategory::Permanent->value)->firstOrFail();
            $contract = EmploymentType::query()->where('category', EmploymentCategory::Contract->value)->firstOrFail();
            $intern = EmploymentType::query()->where('category', EmploymentCategory::Intern->value)->firstOrFail();

            $people = [
                // key, name, gender, dob, joined, dept, designation, grade, branch, type, reports_to, extra
                ['gm', 'Ahmad Faizal bin Rahman', Gender::Male, '1972-04-12', '2012-01-02', $root, $gm, $g5, $hq, $permanent, null, ['marital_status' => 'married', 'tax' => ['spouse_working' => false, 'pcb_category' => 2, 'children_basic' => 2]]],
                ['hrm', 'Nurul Huda binti Ismail', Gender::Female, '1982-09-03', '2015-03-16', $hr, $hrm, $g4, $hq, $permanent, 'gm', ['marital_status' => 'married', 'tax' => ['spouse_working' => true, 'pcb_category' => 3, 'children_basic' => 1]]],
                ['hre', 'Siti Aisyah binti Kamal', Gender::Female, '1993-02-21', '2019-07-01', $hr, $hre, $g2, $hq, $permanent, 'hrm', []],
                ['admin1', 'Mohd Hafiz bin Yusof', Gender::Male, '1996-11-30', '2021-05-03', $hr, $admin, $g1, $hq, $permanent, 'hrm', []],
                ['itm', 'Tan Wei Ming', Gender::Male, '1985-06-15', '2016-08-01', $it, $itm, $g4, $hq, $permanent, 'gm', ['marital_status' => 'married', 'tax' => ['spouse_working' => true, 'pcb_category' => 3, 'children_basic' => 2]]],
                ['dev1', 'Lim Mei Ling', Gender::Female, '1991-01-08', '2018-02-12', $dev, $swe, $g3, $hq, $permanent, 'itm', []],
                ['dev2', 'Muhammad Irfan bin Zulkifli', Gender::Male, '1995-07-19', '2020-09-14', $dev, $swe, $g2, $hq, $permanent, 'dev1', []],
                ['dev3', 'Kavitha a/p Subramaniam', Gender::Female, '1997-03-27', '2022-01-10', $dev, $swe, $g2, $hq, $permanent, 'dev1', []],
                ['dev4', 'Wong Jun Hao', Gender::Male, '2002-05-05', CarbonImmutable::today()->subMonths(2)->toDateString(), $dev, $swe, $g1, $hq, $intern, 'dev1', ['contract_end_date' => CarbonImmutable::today()->addMonths(4)->toDateString()]],
                ['inf1', 'Rajesh a/l Krishnan', Gender::Male, '1988-10-02', '2017-04-03', $inf, $tech, $g2, $tmr, $permanent, 'itm', ['marital_status' => 'married', 'tax' => ['spouse_working' => false, 'pcb_category' => 2]]],
                ['inf2', 'Nor Azman bin Abdullah', Gender::Male, '1990-12-14', '2019-11-18', $inf, $tech, $g1, $tmr, $permanent, 'inf1', []],
                ['inf3', 'Budi Santoso', Gender::Male, '1992-08-08', '2023-02-01', $inf, $tech, $g1, $tmr, $contract, 'inf1', ['foreign' => 'ID', 'contract_end_date' => CarbonImmutable::today()->addDays(25)->toDateString()]],
                ['acc', 'Chong Siew Lan', Gender::Female, '1984-05-23', '2014-06-02', $fin, $acc, $g3, $hq, $permanent, 'gm', ['marital_status' => 'widowed', 'tax' => ['pcb_category' => 3, 'children_basic' => 1]]],
                ['po', 'Farah Nadia binti Hassan', Gender::Female, '1994-09-11', '2020-03-02', $pay, $po, $g2, $hq, $permanent, 'acc', []],
                ['clerk1', 'Aminah binti Salleh', Gender::Female, '1989-01-17', '2016-10-10', $fin, $clerk, $g1, $hq, $permanent, 'acc', ['marital_status' => 'married', 'tax' => ['spouse_working' => true, 'pcb_category' => 3, 'children_basic' => 3]]],
                ['clerk2', 'Lee Chee Keong', Gender::Male, '1998-04-29', '2023-08-21', $fin, $clerk, $g1, $tmr, $permanent, 'acc', []],
                ['admin2', 'Priya a/p Ramasamy', Gender::Female, '2000-06-06', CarbonImmutable::today()->subMonths(1)->toDateString(), $hr, $admin, $g1, $tmr, $permanent, 'hrm', []],
                ['dev5', 'Haziq bin Rosli', Gender::Male, '1999-02-14', '2021-12-01', $dev, $swe, $g2, $hq, $permanent, 'dev1', []],
                ['inf4', 'Joseph Anak Ningkan', Gender::Male, '1987-07-07', '2015-01-05', $inf, $tech, $g2, $tmr, $permanent, 'inf1', ['race' => 'bumiputera_sarawak']],
                ['pr1', 'Chen Xiao Yu', Gender::Female, '1990-10-10', '2018-05-14', $fin, $acc, $g2, $hq, $permanent, 'acc', ['citizenship_status' => 'permanent_resident', 'nationality' => 'CN']],
            ];

            $bank = Bank::query()->where('code', 'MBB')->value('id') ?? Bank::query()->value('id');
            $ids = [];
            foreach ($people as $i => [$key, $name, $gender, $dob, $joined, $dept, $desig, $grade, $branch, $type, $manager, $extra]) {
                $ids[$key] = $this->hire($i + 1, $name, $gender, $dob, $joined, $dept, $desig, $grade, $branch, $type, $manager === null ? null : $ids[$manager], $extra, (int) $bank)->id;
            }

            // Ketua Jabatan
            $root->update(['head_employee_id' => $ids['gm']]);
            $hr->update(['head_employee_id' => $ids['hrm']]);
            $it->update(['head_employee_id' => $ids['itm']]);
            $dev->update(['head_employee_id' => $ids['dev1']]);
            $inf->update(['head_employee_id' => $ids['inf1']]);
            $fin->update(['head_employee_id' => $ids['acc']]);
            $pay->update(['head_employee_id' => $ids['acc']]);

            // Logins
            $this->login($org, $ids['hrm'], 'hr.manager@myhrmis.test', [PermissionMatrix::PENGURUS_HR, PermissionMatrix::KAKITANGAN]);
            $this->login($org, $ids['hre'], 'hr.exec@myhrmis.test', [PermissionMatrix::EKSEKUTIF_HR, PermissionMatrix::KAKITANGAN]);
            $this->login($org, $ids['itm'], 'ketua@myhrmis.test', [PermissionMatrix::KAKITANGAN]);
            $this->login($org, $ids['dev2'], 'staff@myhrmis.test', [PermissionMatrix::KAKITANGAN]);
            $this->login($org, $ids['po'], 'payroll@myhrmis.test', [PermissionMatrix::PEGAWAI_PAYROLL, PermissionMatrix::KAKITANGAN]);
            $this->login($org, $ids['gm'], 'gm@myhrmis.test', [PermissionMatrix::KAKITANGAN]);
            $this->login($org, $ids['dev1'], 'lead.dev@myhrmis.test', [PermissionMatrix::KAKITANGAN]);

            // Pelulus Jabatan (leave): IT department approvers; ancestors used by sub-departments
            $users = fn (string ...$emails) => User::query()->whereIn('email', $emails)->pluck('id')->all();
            app(SaveDepartmentApprovers::class)->handle($it, ['leave' => $users('ketua@myhrmis.test'), 'compensatory_leave' => $users('ketua@myhrmis.test')]);
            app(SaveDepartmentApprovers::class)->handle($dev, ['leave' => $users('lead.dev@myhrmis.test')]);
            app(SaveDepartmentApprovers::class)->handle($hr, ['leave' => $users('hr.manager@myhrmis.test')]);
            app(SaveDepartmentApprovers::class)->handle($root, array_fill_keys(
                ['leave', 'compensatory_leave', 'attendance_request', 'shift_request', 'overtime_claim'],
                $users('gm@myhrmis.test'),
            ));

            $this->holidayList($org, 'PHG', 'Kalendar Cuti Pahang 2026', 'Pahang Holiday Calendar 2026');
        });

        return $org;
    }

    private function organisationSelangor(): Organisation
    {
        $org = Organisation::query()->where('short_code', 'DLS')->first() ?? $this->createOrganisation->handle([
            'name' => 'Demo Logistik Selangor Sdn. Bhd.',
            'short_code' => 'DLS',
            'registration_type' => 'company',
            'ssm_no' => '202101000002',
            'kwsp_employer_no' => '098765432',
            'perkeso_employer_code' => 'E1100000002',
            'lhdn_employer_no' => '9876543210',
            'address_line1' => 'Lot 12, Jalan Industri 3',
            'postcode' => '40000',
            'city' => 'Shah Alam',
            'state' => 'SGR',
            'daily_rate_basis' => 'calendar_days',
            'daily_rate_divisor' => '26.00',
            'is_active' => true,
        ]);

        OrganisationContext::run($org, function () use ($org): void {
            if (Employee::query()->exists()) {
                return;
            }

            $branch = Branch::query()->create(['code' => 'SA', 'name' => 'Gudang Shah Alam', 'name_en' => 'Shah Alam Warehouse', 'state' => 'SGR', 'city' => 'Shah Alam', 'postcode' => '40000']);
            $root = Department::query()->whereNull('parent_id')->firstOrFail();
            $ops = $this->department('OPS', 'Operasi', 'Operations', $root, 'CC-500');
            $mgr = Designation::query()->create(['code' => 'PO', 'name' => 'Pengurus Operasi', 'name_en' => 'Operations Manager']);
            $drv = Designation::query()->create(['code' => 'PMD', 'name' => 'Pemandu', 'name_en' => 'Driver']);
            $permanent = EmploymentType::query()->where('category', 'permanent')->firstOrFail();
            $bank = (int) (Bank::query()->where('code', 'CIMB')->value('id') ?? Bank::query()->value('id'));

            $boss = $this->hire(101, 'Kamarul Ariffin bin Jaafar', Gender::Male, '1980-03-03', '2021-01-04', $ops, $mgr, null, $branch, $permanent, null, [], $bank);
            foreach ([['Zainal bin Osman', Gender::Male, '1991-05-05'], ['Suresh a/l Muthu', Gender::Male, '1993-09-09'], ['Ong Kah Wai', Gender::Male, '1989-12-12'], ['Rosnah binti Ali', Gender::Female, '1995-01-25']] as $i => [$name, $gender, $dob]) {
                $this->hire(102 + $i, $name, $gender, $dob, '2022-06-01', $ops, $drv, null, $branch, $permanent, $boss->id, [], $bank);
            }
            $ops->update(['head_employee_id' => $boss->id]);

            $this->login($org, null, 'hr.selangor@myhrmis.test', [PermissionMatrix::PENGURUS_HR], 'Pengurus HR Selangor');
            $this->holidayList($org, 'SGR', 'Kalendar Cuti Selangor 2026', 'Selangor Holiday Calendar 2026');
        });

        return $org;
    }

    private function department(string $code, string $name, string $en, Department $parent, string $costCentre): Department
    {
        return Department::query()->create([
            'code' => $code, 'name' => $name, 'name_en' => $en, 'parent_id' => $parent->id, 'cost_centre_code' => $costCentre,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function hire(int $seed, string $name, Gender $gender, string $dob, string $joined, Department $dept, Designation $designation, ?Grade $grade, Branch $branch, EmploymentType $type, ?int $managerId, array $extra, int $bankId): Employee
    {
        $birth = CarbonImmutable::parse($dob);
        $foreign = $extra['foreign'] ?? null;
        unset($extra['foreign']);

        $identity = $foreign === null ? [
            'identity_type' => 'mykad',
            'ic_no' => FakeMyKad::make($birth, $gender, $seed * 7919),
            'citizenship_status' => 'citizen',
            'nationality' => 'MY',
        ] : [
            'identity_type' => 'passport',
            'passport_no' => 'X'.str_pad((string) ($seed * 1234567 % 10000000), 7, '0', STR_PAD_LEFT),
            'passport_country' => $foreign,
            'passport_expiry' => CarbonImmutable::today()->addYears(2)->toDateString(),
            'citizenship_status' => 'foreign',
            'nationality' => $foreign,
            'date_of_birth' => $dob,
            'gender' => $gender->value,
            'work_pass_type' => 'temporary_employment_pass',
            'work_pass_no' => 'PLKS'.str_pad((string) $seed, 7, '0', STR_PAD_LEFT),
            'work_pass_expiry' => CarbonImmutable::today()->addDays(80)->toDateString(),
        ];

        $tax = $extra['tax'] ?? [];
        unset($extra['tax']);

        $data = array_merge([
            'full_name' => $name,
            'mobile_phone' => '01'.(2 + $seed % 7).'-'.str_pad((string) (5000000 + $seed * 37), 7, '0', STR_PAD_LEFT),
            'personal_email' => 'kakitangan'.$seed.'@contoh.test',
            'work_email' => 'staf'.$seed.'@'.strtolower(OrganisationContext::current()->short_code).'.example',
            'marital_status' => 'single',
            'race' => match (true) {
                str_contains($name, ' a/p ') || str_contains($name, ' a/l ') => 'indian',
                str_contains($name, ' bin ') || str_contains($name, ' binti ') => 'malay',
                $foreign !== null => 'other',
                default => 'chinese',
            },
            'address_line1' => 'No. '.$seed.', Jalan Contoh '.$seed,
            'postcode' => OrganisationContext::current()->postcode,
            'city' => OrganisationContext::current()->city,
            'state' => OrganisationContext::current()->state->value,
            'country' => 'MY',
            'date_of_joining' => $joined,
            'branch_id' => $branch->id,
            'department_id' => $dept->id,
            'designation_id' => $designation->id,
            'grade_id' => $grade?->id,
            'employment_type_id' => $type->id,
            'reports_to_id' => $managerId,
            'confirmed_on' => CarbonImmutable::parse($joined)->addMonths(3)->lessThan(CarbonImmutable::today()) ? CarbonImmutable::parse($joined)->addMonths(3)->toDateString() : null,
            'kwsp_no' => $foreign === null ? (string) (10000000 + $seed * 101) : null,
            'perkeso_no' => $foreign === null ? null : 'SSFW'.str_pad((string) $seed, 6, '0', STR_PAD_LEFT),
            'salary_payment_mode' => 'bank_transfer',
            'bank_id' => $bankId,
            'bank_account_no' => str_pad((string) (1000000000 + $seed * 7777), 12, '0', STR_PAD_LEFT),
            'bank_account_name' => $name,
            'tax' => array_merge(['tax_residency' => 'resident', 'mtd_scheme' => 'normal', 'pcb_category' => 1], $tax),
            'emergency_contacts' => [[
                'name' => 'Waris '.$seed, 'relationship' => 'parent', 'phone' => '019-'.str_pad((string) (1000000 + $seed), 7, '0', STR_PAD_LEFT), 'is_primary' => true,
            ]],
        ], $identity, $extra);

        if (($data['marital_status'] ?? 'single') === 'married' && ! array_key_exists('spouse_working', $data['tax'])) {
            $data['tax']['spouse_working'] = true;
            $data['tax']['pcb_category'] = 3;
        }

        return $this->createEmployee->handle($data)['employee'];
    }

    /**
     * @param  list<string>  $roles
     */
    private function login(Organisation $org, ?int $employeeId, string $email, array $roles, ?string $name = null): User
    {
        $employee = $employeeId === null ? null : Employee::query()->find($employeeId);
        $user = $this->user($name ?? $employee->full_name ?? $email, $email);
        $user->forceFill(['current_organisation_id' => $org->id])->save();

        foreach ($roles as $role) {
            $this->roles->assign($user, $org->id, $role);
        }
        $employee?->update(['user_id' => $user->id]);

        return $user;
    }

    private function user(string $name, string $email): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => self::PASSWORD,
            'locale' => 'ms',
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function holidayList(Organisation $org, string $state, string $name, string $nameEn): void
    {
        $list = HolidayList::query()->create([
            'name' => $name,
            'name_en' => $nameEn,
            'from_date' => '2026-01-01',
            'to_date' => '2026-12-31',
            'state' => $state,
            'weekly_off_days' => [6, 7],
            'half_day_weekly_off_days' => [],
            'is_active' => true,
        ]);

        app(ImportPublicHolidays::class)->handle($list, 2026, ['federal', $state]);
        app(GenerateWeeklyOffs::class)->handle($list);

        $actions = app(HolidayAssignmentActions::class);
        $assignment = $actions->saveDraft(null, [
            'holiday_list_id' => $list->id,
            'assignable_type' => 'organisation',
            'assignable_id' => $org->id,
            'effective_from' => '2026-01-01',
            'remarks' => 'Senarai lalai Organisasi',
        ]);
        $actions->submit($assignment, User::query()->where('email', 'admin@myhrmis.test')->firstOrFail());
    }
}
