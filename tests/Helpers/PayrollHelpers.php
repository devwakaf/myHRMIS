<?php

/*
| Helpers for the M4 (Payroll + Statutori) test suite. Loaded from tests/Pest.php.
| Common fixture (spec-04 §8): Organisasi A (Pahang, HRD Corp registered 1%, ÷26),
| Sat–Sun weekly off with no public holidays, January 2026 unless stated.
|
| Rate sets are TEST sets, all `verified`: KWSP rules + placeholder bands (equal to
| ceil(band upper × rate), which reproduces every fixture in §7.1/§8); PERKESO and SIP
| placeholder bands with the spec's [V-PDF] rows written over them; SKBBK starting
| 2026-01-01 so the January fixtures include it as the spec's expected nets do (the
| production seed uses 2026-06-01). LHDN Table 1 YA2026 is the real seeded table.
*/

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeTaxProfile;
use App\Domain\Payroll\Actions\AssignmentActions;
use App\Domain\Payroll\Actions\PayrollRunActions;
use App\Domain\Payroll\Actions\StartPayrollGeneration;
use App\Domain\Payroll\Actions\SubmitPayrollRun;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\PlaceholderBands;
use App\Domain\Statutory\Services\StatutoryRateRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\DB;

function testRateSet(RateScheme $scheme, string $code, string $from, ?callable $fill = null, bool $placeholder = false): StatutoryRateSet
{
    $set = new StatutoryRateSet;
    $set->forceFill(['scheme' => $scheme, 'code' => $code, 'effective_from' => $from, 'status' => 'unverified', 'source_url' => 'test'])->save();
    if ($fill !== null) {
        $fill($set->id);
    }
    if ($placeholder) {
        app(PlaceholderBands::class)->generate($set);
    }
    $set->forceFill(['status' => 'verified', 'verified_at' => now()])->save();

    return $set;
}

/** Verified test rate sets for every scheme (see header). */
function payrollRates(string $percentRounding = 'ceil_each_share'): void
{
    testRateSet(RateScheme::Epf, 'T-EPF', '2025-10-01', function (int $id) use ($percentRounding) {
        StatutoryRatesSeeder::epfRules($id, $percentRounding);
        StatutoryRatesSeeder::epfParams($id);
    }, true);
    $socso = testRateSet(RateScheme::Socso, 'T-SOCSO', '2024-10-01', fn (int $id) => StatutoryRatesSeeder::socsoParams($id, ['skbbk_start' => '2026-01-01']), true);
    // [V-PDF] rows quoted by spec-04 §8 (cases 1 and 3).
    DB::table('socso_bands')->where('rate_set_id', $socso->id)->where('wage_from', '3200.01')
        ->update(['cat1_employer' => '56.85', 'cat1_employee' => '16.25', 'cat1_skbbk_employee' => '24.40', 'cat2_employer' => '40.65', 'cat2_skbbk_employee' => '24.40']);
    testRateSet(RateScheme::Eis, 'T-EIS', '2024-10-01', fn (int $id) => StatutoryRatesSeeder::eisParams($id), true);
    testRateSet(RateScheme::Mtd, 'T-MTD', '2026-01-01', fn (int $id) => StatutoryRatesSeeder::mtdTable($id));
    testRateSet(RateScheme::TaxReliefs, 'T-REL', '2026-01-01', fn (int $id) => StatutoryRatesSeeder::reliefRows($id));
    testRateSet(RateScheme::Hrdc, 'T-HRDC', '2026-01-01', fn (int $id) => StatutoryRatesSeeder::hrdcRules($id));
    testRateSet(RateScheme::MinimumWage, 'T-MW', '2025-08-01', fn (int $id) => StatutoryRatesSeeder::minimumWageRow($id));
    StatutoryRatesSeeder::fileFormats();
    StatutoryRatesSeeder::bankFormats();
    app(StatutoryRateRepository::class)->flush();
}

function payrollWorld(string $now = '2026-01-10'): stdClass
{
    test()->travelTo(CarbonImmutable::parse($now.' 09:00:00'));

    $w = new stdClass;
    $w->org = inOrg(makeOrganisation(['hrd_corp_registered' => true, 'hrd_corp_levy_rate' => '1.0000']));
    $w->hr = userIn($w->org, 'pengurus_hr', ['name' => 'Pengurus HR']);
    $w->po = userIn($w->org, 'pegawai_payroll', ['name' => 'Pegawai Payroll']);
    $w->pa = userIn($w->org, 'pelulus_payroll', ['name' => 'Pelulus Payroll']);
    $w->fin = userIn($w->org, 'kewangan', ['name' => 'Kewangan']);
    $w->list = leaveHolidayList($w->hr);
    payrollRates();
    $w->structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();

    return $w;
}

function component(string $code): SalaryComponent
{
    return SalaryComponent::query()->where('code', $code)->firstOrFail();
}

/**
 * A Kakitangan with a submitted assignment.
 *
 * @param  array<string, mixed>  $attributes  employee columns (+ 'tax' => tax profile columns)
 * @param  array<string, string>  $amounts  component code => override amount
 */
function payee(array $attributes = [], string $base = '3000.00', array $amounts = [], ?string $from = null, ?SalaryStructure $structure = null): Employee
{
    $tax = $attributes['tax'] ?? [];
    unset($attributes['tax']);
    $user = userIn(OrganisationContext::current(), 'kakitangan');
    $employee = employee($attributes + [
        'date_of_joining' => '2020-01-01', 'date_of_birth' => '1990-05-15', 'kwsp_no' => fake()->numerify('########'),
        'perkeso_no' => fake()->numerify('##########'),
    ], $user);
    EmployeeTaxProfile::query()->where('employee_id', $employee->id)->update($tax + ['tin' => 'IG'.fake()->numerify('##########')]);

    $structure ??= SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $overrides = [];
    foreach ($amounts as $code => $amount) {
        $overrides[component($code)->id] = $amount;
    }
    $actions = app(AssignmentActions::class);
    $draft = $actions->saveDraft(null, [
        'employee_id' => $employee->id, 'salary_structure_id' => $structure->id,
        'effective_from' => $from ?? $employee->date_of_joining->toDateString(), 'base_salary' => $base, 'amounts' => $overrides,
    ]);
    $actions->submit($draft, null);

    return $employee->refresh();
}

function raise(Employee $employee, string $from, string $base): SalaryAssignment
{
    $actions = app(AssignmentActions::class);
    $draft = $actions->saveDraft(null, [
        'employee_id' => $employee->id, 'salary_structure_id' => SalaryStructure::query()->where('code', 'STANDARD')->value('id'),
        'effective_from' => $from, 'base_salary' => $base, 'reason' => 'increment',
    ]);

    return $actions->submit($draft, null);
}

function runFor(string $period, User $preparer, array $filters = []): PayrollRun
{
    $run = app(PayrollRunActions::class)->create(['period' => $period, 'filters' => $filters], $preparer);

    return app(StartPayrollGeneration::class)->handle($run, $preparer);
}

function slipOf(Employee $employee, ?PayrollRun $run = null): SalarySlip
{
    return SalarySlip::query()->where('employee_id', $employee->id)
        ->when($run !== null, fn ($q) => $q->where('payroll_run_id', $run?->id))
        ->where('status', '!=', 'cancelled')->latest('id')->firstOrFail();
}

function line(SalarySlip $slip, string $code): string
{
    return (string) ($slip->lines()->where('code', $code)->value('amount') ?? '0.00');
}

function submitRun(PayrollRun $run, User $approver): PayrollRun
{
    return app(SubmitPayrollRun::class)->handle($run->refresh(), $approver);
}

/** Generate + submit one month for the given preparer/approver. */
function payMonth(string $period, stdClass $w): PayrollRun
{
    return submitRun(runFor($period, $w->po), $w->pa);
}
