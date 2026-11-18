<?php

// spec-04 §8.1 — statutory worked slips (cases 1–14). [V-PDF]/[V-LHDN] values must be
// confirmed against the official tables / LHDN calculator before the tests are frozen.

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Payroll\Actions\AdditionalSalaryActions;
use App\Domain\Payroll\Actions\CancelPayrollRun;
use App\Domain\Payroll\Actions\StartPayrollGeneration;
use App\Domain\Payroll\Actions\TaxDeclarationActions;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Statutory\Enums\AgeSwitchRule;
use App\Domain\Statutory\Enums\CitizenshipGroup;
use App\Domain\Statutory\Enums\EpfBonusBase;
use App\Domain\Statutory\Enums\EpfPart;
use App\Domain\Statutory\Enums\EpfPercentRounding;
use App\Domain\Statutory\Services\EpfCalculator;
use App\Domain\Statutory\Services\MtdCalculator;
use App\Domain\Statutory\Support\EpfRule;
use App\Domain\Statutory\Support\EpfTable;
use App\Domain\Statutory\Support\StatutoryPerson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeUnpaidLeaveQuery;

beforeEach(function () {
    $this->w = payrollWorld('2026-01-10');
});

function statutory(SalarySlip $slip): array
{
    return $slip->statutory()->firstOrFail()->toArray();
}

function addPay(string $employeeId, string $code, string $amount, string $period, array $extra = []): void
{
    $actions = app(AdditionalSalaryActions::class);
    $row = $actions->saveDraft(null, $extra + ['employee_id' => $employeeId, 'salary_component_id' => component($code)->id, 'kind' => 'one_off',
        'period' => $period, 'amount' => $amount, 'mode' => 'add', 'reason' => 'Ujian']);
    $actions->submit($row, test()->w->po);
}

// #1
it('computes a citizen under 60 on RM3,250, category 1 [V-PDF]', function () {
    $e = payee([], '3250.00');
    runFor('2026-01-01', $this->w->po);
    $slip = slipOf($e);
    $st = statutory($slip);

    expect($st['epf_employee'])->toBe('359.00')->and($st['epf_employer'])->toBe('424.00')
        ->and($st['socso_employer'])->toBe('56.85')->and($st['socso_employee'])->toBe('16.25')->and($st['skbbk_employee'])->toBe('24.40')
        ->and($st['eis_employee'])->toBe('6.50')->and($st['eis_employer'])->toBe('6.50')
        ->and($st['pcb_net'])->toBe('0.00')->and($st['hrdc_levy'])->toBe('32.50')
        ->and($st['pcb_inputs']['P'])->toBe('26000')
        ->and((string) $slip->net_pay)->toBe('2843.85');
});

// #2
it('computes RM6,710.80 with the PERKESO ceiling row and PCB 285.70 [V-PDF] [V-LHDN]', function () {
    $e = payee([], '6000.00', ['ELAUN_TETAP' => '710.80']);
    runFor('2026-01-01', $this->w->po);
    $slip = slipOf($e);
    $st = statutory($slip);

    expect($st['epf_employee'])->toBe('748.00')->and($st['epf_employer'])->toBe('816.00')
        ->and($st['socso_employer'])->toBe('104.15')->and($st['skbbk_employee'])->toBe('44.65')->and($st['socso_employee'])->toBe('29.75')
        ->and($st['eis_employee'])->toBe('11.90')->and($st['eis_employer'])->toBe('11.90')
        ->and($st['pcb_inputs']['P'])->toBe('67529.6')->and($st['pcb_inputs']['T'])->toBe('3428.256')
        ->and($st['pcb_net'])->toBe('285.70')->and($st['hrdc_levy'])->toBe('67.11')
        ->and((string) $slip->gross_pay)->toBe('6710.80')->and((string) $slip->net_pay)->toBe('5590.80');
});

// #3
it('uses Part E, PERKESO category 2 and no SIP at age 61 [V-PDF]', function () {
    $e = payee(['date_of_birth' => '1964-12-01'], '3250.00');
    runFor('2026-01-01', $this->w->po);
    $st = statutory(slipOf($e));

    expect($st['epf_part'])->toBe('E')->and($st['epf_employer'])->toBe('131.00')->and($st['epf_employee'])->toBe('0.00')
        ->and($st['socso_category'])->toBe('2')->and($st['socso_employer'])->toBe('40.65')->and($st['socso_employee'])->toBe('0.00')
        ->and($st['skbbk_employee'])->toBe('24.40')
        ->and($st['eis_eligible'])->toBeFalse()->and($st['eis_employee'])->toBe('0.00')
        ->and($st['pcb_inputs']['P'])->toBe('30000')->and($st['pcb_inputs']['T'])->toBe('50')->and($st['pcb_net'])->toBe('0.00');
});

// #3 variant (age switch rule UNVERIFIED)
it('switches to Part E in the month after the 60th birthday', function () {
    $e = payee(['date_of_birth' => '1966-01-15'], '3250.00');
    payMonth('2026-01-01', $this->w);
    expect(statutory(slipOf($e))['epf_part'])->toBe('A');

    runFor('2026-02-01', $this->w->po);
    expect(statutory(slipOf($e))['epf_part'])->toBe('E');
});

// #4
it('computes a foreign worker: Part F, mandatory SKBBK, no SIP, no HRDC; non-resident 30%', function () {
    $e = payee(['identity_type' => 'passport', 'ic_no' => null, 'citizenship_status' => 'foreign', 'nationality' => 'ID',
        'passport_no' => 'A1234567', 'passport_country' => 'ID', 'work_pass_type' => 'temporary_employment_pass',
        'work_pass_no' => 'PLKS1', 'work_pass_expiry' => '2027-06-30'], '2000.00');
    DB::table('lindung24_elections')->insert(['organisation_id' => $this->w->org->id, 'employee_id' => $e->id, 'status' => 'opted_out',
        'effective_month' => '2026-01-01', 'effective_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);

    runFor('2026-01-01', $this->w->po);
    $st = statutory(slipOf($e));
    expect($st['epf_part'])->toBe('F')->and($st['epf_employee'])->toBe('40.00')->and($st['epf_employer'])->toBe('40.00')
        ->and($st['socso_employer'])->toBe('34.15')->and($st['socso_employee'])->toBe('9.75')
        ->and($st['skbbk_applies'])->toBeTrue()->and($st['skbbk_employee'])->not->toBe('0.00')
        ->and($st['eis_employee'])->toBe('0.00')->and($st['hrdc_levy'])->toBe('0.00')
        ->and($st['pcb_inputs']['P'])->toBe('14520')->and($st['pcb_net'])->toBe('0.00');
});

it('charges a non-resident a flat 30%', function () {
    $e = payee(['tax' => ['tax_residency' => 'non_resident']], '2000.00');
    runFor('2026-01-01', $this->w->po);
    expect(statutory(slipOf($e))['pcb_net'])->toBe('600.00');
});

it('stops KWSP two months before the work pass expires, with a warning', function () {
    $e = payee(['identity_type' => 'passport', 'ic_no' => null, 'citizenship_status' => 'foreign', 'passport_no' => 'B7654321',
        'passport_country' => 'ID', 'work_pass_expiry' => '2026-02-20'], '2000.00');
    runFor('2026-01-01', $this->w->po);
    $slip = slipOf($e);
    expect(statutory($slip)['epf_part'])->toBe('none')->and(statutory($slip)['epf_employee'])->toBe('0.00')
        ->and(collect($slip->warnings)->contains(fn ($w) => str_contains($w, 'Caruman KWSP dihentikan')))->toBeTrue();
});

it('rounds Part F per the configured rule (UNVERIFIED) on RM1,850.50', function (EpfPercentRounding $mode, string $employer, string $employee) {
    $rule = new EpfRule(EpfPart::F, CitizenshipGroup::Foreign, 0, 75, null, '2', '2', '2', null, $mode, false, EpfBonusBase::BandUpper, false);
    $table = new EpfTable(1, 'T', 14, 75, AgeSwitchRule::MonthAfterBirthday, 2, [$rule], []);
    $person = new StatutoryPerson(CitizenshipStatus::Foreign, CarbonImmutable::parse('1990-01-01'), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
    $r = app(EpfCalculator::class)->calculate($table, $person, '1850.50', '1850.50', '1850.50');
    expect($r->employer)->toBe($employer)->and($r->employee)->toBe($employee);
})->with([
    'ceil_each_share' => [EpfPercentRounding::CeilEachShare, '38.00', '38.00'],
    'ceil_total_then_split' => [EpfPercentRounding::CeilTotalThenSplit, '37.00', '38.00'],
]);

// #5
it('applies the KWSP bonus rule and the additional-remuneration PCB [V-LHDN]', function () {
    $e = payee([], '4500.00');
    addPay((string) $e->id, 'BONUS', '2000.00', '2026-01-01');
    runFor('2026-01-01', $this->w->po);
    $slip = slipOf($e);
    $st = statutory($slip);

    expect($st['epf_wage'])->toBe('6500.00')->and($st['epf_employer'])->toBe('845.00')->and($st['epf_employee'])->toBe('715.00')
        ->and($st['epf_employee_normal'])->toBe('495.00')
        ->and($st['socso_wage'])->toBe('4500.00')->and($st['eis_wage'])->toBe('4500.00')->and($st['hrdc_levy'])->toBe('45.00')
        ->and($st['pcb_inputs']['P'])->toBe('41000')->and($st['pcb_inputs']['T'])->toBe('960')->and($st['pcb_normal'])->toBe('80.00')
        ->and($st['pcb_inputs']['P_additional'])->toBe('43000')->and($st['pcb_inputs']['T_additional'])->toBe('1080')
        ->and($st['pcb_additional'])->toBe('120.00')->and($st['pcb_net'])->toBe('200.00')
        ->and(line($slip, 'BONUS'))->toBe('2000.00');
});

// #6
it('compares PCB categories [V-LHDN]', function (string $base, int $category, int $children, string $pcb) {
    $e = payee(['tax' => ['pcb_category' => $category, 'children_basic' => $children, 'claims_child_relief' => true]], $base);
    runFor('2026-01-01', $this->w->po);
    expect(statutory(slipOf($e))['pcb_net'])->toBe($pcb);
})->with([
    'RM8,000 cat 1' => ['8000.00', 1, 0, '514.20'],
    'RM8,000 cat 2, 2 children' => ['8000.00', 2, 2, '387.50'],
    'RM8,000 cat 3, 2 children' => ['8000.00', 3, 2, '450.85'],
    'RM4,000 cat 1 (boundary 35,000)' => ['4000.00', 1, 0, '16.70'],
    'RM4,000 cat 2, no children' => ['4000.00', 2, 0, '0.00'],
]);

// #7
it('offsets zakat via payroll against PCB and carries it as Z [V-LHDN]', function () {
    $monthly = payee(['tax' => ['zakat_via_payroll' => true, 'zakat_monthly_amount' => '100.00']], '8000.00');
    $oneOff = payee([], '8000.00');
    addPay((string) $oneOff->id, 'ZAKAT', '600.00', '2026-01-01');
    $external = payee([], '8000.00');
    $dec = app(TaxDeclarationActions::class)->save(null, ['employee_id' => $external->id, 'type' => 'zakat_external', 'period' => '2026-01-01', 'zakat_amount' => '600']);
    app(TaxDeclarationActions::class)->submit($dec, $this->w->po);

    payMonth('2026-01-01', $this->w);
    expect(statutory(slipOf($monthly))['pcb_net'])->toBe('414.20')->and(line(slipOf($monthly), 'ZAKAT'))->toBe('100.00')
        ->and(statutory(slipOf($oneOff))['pcb_net'])->toBe('0.00')
        ->and(statutory(slipOf($external))['pcb_net'])->toBe('0.00')->and(statutory(slipOf($external))['zakat_external'])->toBe('600.00');

    runFor('2026-02-01', $this->w->po);
    foreach ([$oneOff, $external] as $e) {
        $st = statutory(slipOf($e));
        expect($st['pcb_inputs']['Z'])->toBe('600')->and($st['pcb_inputs']['K2'])->toBe('224')
            ->and($st['pcb_inputs']['P'])->toBe('83000')->and($st['pcb_net'])->toBe('506.40');
    }
});

// #8
it('pro-rates a mid-month joiner by the daily-rate basis', function (string $basis, string $partial, string $expected) {
    Organisation::query()->whereKey($this->w->org->id)->update(['daily_rate_basis' => $basis, 'daily_rate_divisor' => '26']);
    PayrollSettings::current()->forceFill(['partial_month_basis' => $partial])->save();
    $e = payee(['date_of_joining' => '2026-03-16'], '3000.00');
    runFor('2026-03-01', $this->w->po);
    $slip = slipOf($e);
    expect(line($slip, 'GAJI_POKOK'))->toBe($expected)->and(statutory($slip)['pcb_net'])->toBe('0.00');
})->with([
    '÷26' => ['fixed_divisor', 'follow_daily_rate', '1846.15'],
    'calendar' => ['calendar_days', 'follow_daily_rate', '1548.39'],
    'working days' => ['working_days', 'follow_daily_rate', '1636.36'],
    'partial = calendar with ÷26' => ['fixed_divisor', 'calendar_days', '1548.39'],
]);

it('takes KWSP on the pro-rated joiner wage from the band (205/242)', function () {
    $e = payee(['date_of_joining' => '2026-03-16'], '3000.00');
    runFor('2026-03-01', $this->w->po);
    $st = statutory(slipOf($e));
    expect($st['epf_employee'])->toBe('205.00')->and($st['epf_employer'])->toBe('242.00')
        ->and(slipOf($e)->outside_employment_days)->toBe('10.000');
});

// #9
it('deducts unpaid leave per basis and presentation', function (string $basis, string $presentation, array $days, string $basic, ?string $ctg, string $epf) {
    Organisation::query()->whereKey($this->w->org->id)->update(['daily_rate_basis' => $basis, 'daily_rate_divisor' => '26']);
    PayrollSettings::current()->forceFill(['lwp_presentation' => $presentation])->save();
    $e = payee([], '3000.00');
    $fake = new FakeUnpaidLeaveQuery;
    $fake->days[$e->id] = $days;
    app()->instance(UnpaidLeaveQuery::class, $fake);

    runFor('2026-03-01', $this->w->po);
    $slip = slipOf($e);
    expect(line($slip, 'GAJI_POKOK'))->toBe($basic)->and(statutory($slip)['epf_employee'])->toBe($epf);
    if ($ctg !== null) {
        expect(line($slip, 'POTONGAN_CTG'))->toBe($ctg)->and(statutory($slip)['epf_wage'])->toBe('2769.23');
    }
})->with([
    '÷26' => ['fixed_divisor', 'reduce_earning', ['2026-03-10' => '1', '2026-03-11' => '1'], '2769.23', null, '306.00'],
    'calendar' => ['calendar_days', 'reduce_earning', ['2026-03-10' => '1', '2026-03-11' => '1'], '2806.45', null, '311.00'],
    'working days' => ['working_days', 'reduce_earning', ['2026-03-10' => '1', '2026-03-11' => '1'], '2727.27', null, '302.00'],
    'separate deduction ÷26' => ['fixed_divisor', 'separate_deduction', ['2026-03-10' => '1', '2026-03-11' => '1'], '3000.00', '230.77', '306.00'],
    'half day ÷26' => ['fixed_divisor', 'reduce_earning', ['2026-03-10' => '0.5'], '2942.31', null, '326.00'],
]);

it('keeps net pay and KWSP identical in both LWP presentations', function () {
    $e = payee([], '3000.00');
    $fake = new FakeUnpaidLeaveQuery;
    $fake->days[$e->id] = ['2026-03-10' => '1', '2026-03-11' => '1'];
    app()->instance(UnpaidLeaveQuery::class, $fake);

    $run = runFor('2026-03-01', $this->w->po);
    $reduced = slipOf($e);
    $net = (string) $reduced->net_pay;
    $epf = statutory($reduced)['epf_employee'];

    app(CancelPayrollRun::class)->discardSlips($run->refresh(), $this->w->po);
    PayrollSettings::current()->forceFill(['lwp_presentation' => 'separate_deduction'])->save();
    app(StartPayrollGeneration::class)->handle($run->refresh(), $this->w->po);
    $separate = slipOf($e);

    expect((string) $separate->net_pay)->toBe($net)->and(statutory($separate)['epf_employee'])->toBe($epf)
        ->and((string) $separate->gross_pay)->toBe('3000.00');
});

// #10
it('handles December (n = 0) without a division error', function () {
    $e = payee([], '8000.00');
    SalaryAssignment::query()->where('employee_id', $e->id)->update([
        'opening_year' => 2026, 'opening_gross_normal' => '88000.00', 'opening_epf_employee' => '3520.00', 'opening_pcb' => '5000.00',
    ]);
    runFor('2026-12-01', $this->w->po);
    $st = statutory(slipOf($e));
    expect($st['pcb_inputs']['n'])->toBe(0)->and($st['pcb_inputs']['K2'])->toBe('0')->and($st['pcb_inputs']['K1e'])->toBe('480')
        ->and($st['pcb_inputs']['P'])->toBe('83000')->and($st['pcb_net'])->toBe('1170.00');
});

// #11
it('uses the percentage method above RM20,000', function () {
    $e = payee([], '25000.50');
    runFor('2026-01-01', $this->w->po);
    $st = statutory(slipOf($e));
    expect($st['epf_method'])->toBe('percent')->and($st['epf_employee'])->toBe('2751.00')->and($st['epf_employer'])->toBe('3001.00');
});

// #12
it('applies the PCB minimum rules', function () {
    expect(MtdCalculator::applyMinimum('9.95', '10'))->toBe('0.00')
        ->and(MtdCalculator::netOfZakat('30.00', '25.00'))->toBe('5.00')
        ->and(MtdCalculator::applyMinimum('8.00', '10'))->toBe('0.00')
        ->and(MtdCalculator::applyMinimum('10.00', '10'))->toBe('10.00');
});

// #13
it('includes TP3 previous-employer income and opening YTD; the K cap stops K2', function () {
    $e = payee(['date_of_joining' => '2026-07-01'], '7000.00');
    $dec = app(TaxDeclarationActions::class)->save(null, ['employee_id' => $e->id, 'type' => 'tp3', 'period' => '2026-07-01',
        'prev_employer_name' => 'Syarikat Lama', 'prev_gross_normal' => '30000', 'prev_epf' => '3300', 'prev_pcb' => '1200']);
    app(TaxDeclarationActions::class)->submit($dec, $this->w->po);

    runFor('2026-07-01', $this->w->po);
    $in = statutory(slipOf($e))['pcb_inputs'];
    expect($in['Y'])->toBe('30000')->and($in['K'])->toBe('3300')->and($in['X'])->toBe('1200')
        ->and($in['K1e'])->toBe('700')->and($in['K2'])->toBe('0')->and($in['n'])->toBe(5);
});

// #14
it('rounds with the shared Rounding unit', function () {
    expect((string) Rounding::upToFiveSen('285.688'))->toBe('285.70')
        ->and((string) Rounding::upToFiveSen('287.02'))->toBe('287.05')
        ->and((string) Rounding::ceilRinggit('423.80'))->toBe('424');
});
