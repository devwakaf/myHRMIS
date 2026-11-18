<?php

// spec-04 §8.3 — run flow, locking, adjustments (cases 23–35).

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Contracts\OtWageBaseProvider;
use App\Domain\Attendance\Support\OvertimePayLine;
use App\Domain\Core\Models\Bank;
use App\Domain\Leave\Contracts\AdditionalPayPort;
use App\Domain\Leave\Contracts\CompensationQuery;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Leave\Enums\PayoutStatus;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Payroll\Actions\AdditionalSalaryActions;
use App\Domain\Payroll\Actions\ArrearActions;
use App\Domain\Payroll\Actions\CancelPayrollRun;
use App\Domain\Payroll\Actions\CorrectionActions;
use App\Domain\Payroll\Actions\MarkPayrollRunPaid;
use App\Domain\Payroll\Actions\PayrollRunActions;
use App\Domain\Payroll\Actions\RefreshTaxYear;
use App\Domain\Payroll\Actions\StartPayrollGeneration;
use App\Domain\Payroll\Actions\StructureActions;
use App\Domain\Payroll\Actions\SubmitPayrollRun;
use App\Domain\Payroll\Actions\TaxDeclarationActions;
use App\Domain\Payroll\Actions\WithholdingActions;
use App\Domain\Payroll\Enums\CorrectionType;
use App\Domain\Payroll\Enums\GenerationStatus;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Exports\PayrollExports;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\EmployeeTaxYear;
use App\Domain\Payroll\Models\OrganisationBankAccount;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryWithholdingCycle;
use App\Domain\Payroll\Services\TaxYearCalculator;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\DocumentLocked;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Money;
use App\Domain\Statutory\Models\StatutoryRateSet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAttendanceForPayroll;
use Tests\Support\FakeUnpaidLeaveQuery;

beforeEach(function () {
    Storage::fake('local');
    $this->w = payrollWorld('2026-01-10');
});

function additional(int $employeeId, string $code, string $amount, array $extra = []): AdditionalSalary
{
    $actions = app(AdditionalSalaryActions::class);
    $row = $actions->saveDraft(null, $extra + ['employee_id' => $employeeId, 'salary_component_id' => component($code)->id,
        'kind' => 'one_off', 'period' => '2026-01-01', 'amount' => $amount, 'mode' => 'add', 'reason' => 'Ujian']);

    return $actions->submit($row, test()->w->po);
}

function bankAccount(): OrganisationBankAccount
{
    $account = new OrganisationBankAccount(['bank_id' => Bank::query()->value('id') ?? Bank::factory()->create()->id, 'account_no' => '512345678901', 'account_name' => 'Organisasi A', 'is_default' => true]);
    $account->save();

    return $account;
}

// #23
it('isolates per-employee failures and still generates the others', function () {
    $ok = [payee([], '3000.00'), payee([], '3200.00'), payee([], '3400.00')];
    $late = payee([], '3000.00', [], '2026-01-20');               // assignment starts after the period start
    $structure = app(StructureActions::class)->save(null, ['code' => 'BAHAGI', 'name_ms' => 'Ujian', 'rows' => [
        ['salary_component_id' => component('GAJI_POKOK')->id],
        ['salary_component_id' => component('ELAUN_TETAP')->id, 'calculation_override' => 'formula', 'formula' => 'ABS(100 / (BASE - 3100))'],
    ]])['structure'];
    $bad = payee([], '3100.00', [], null, $structure);

    $run = runFor('2026-01-01', $this->w->po);

    $rows = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->get()->keyBy('employee_id');
    expect($rows[$late->id]->generation_status)->toBe(GenerationStatus::Failed)
        ->and($rows[$late->id]->error_message)->toContain('Tiada Penugasan Struktur Gaji')
        ->and($rows[$bad->id]->generation_status)->toBe(GenerationStatus::Failed)
        ->and($rows[$bad->id]->error_message)->toContain('Pembahagian dengan sifar');
    foreach ($ok as $e) {
        expect($rows[$e->id]->generation_status)->toBeIn([GenerationStatus::Success, GenerationStatus::Warning]);
    }
    expect($run->status)->toBe(PayrollRunStatus::SlipsGenerated)->and($run->employee_count)->toBe(3);
});

// #24
it('refuses a second concurrent "Jana Slip"', function () {
    payee();
    $run = app(PayrollRunActions::class)->create(['period' => '2026-01-01'], $this->w->po);
    DB::table('payroll_runs')->where('id', $run->id)->update(['processing_state' => 'queued']);
    expect(fn () => app(StartPayrollGeneration::class)->handle($run, $this->w->po))
        ->toThrow(PayrollRuleViolation::class, 'payroll.errors.run_busy');
});

// #25
it('skips an employee already claimed by another run of the month', function () {
    $e = payee();
    runFor('2026-01-01', $this->w->po);
    $second = runFor('2026-01-01', $this->w->po);
    $row = PayrollRunEmployee::query()->where('payroll_run_id', $second->id)->where('employee_id', $e->id)->firstOrFail();
    expect($row->generation_status)->toBe(GenerationStatus::Skipped)->and($row->error_message)->toContain('Sudah ada dalam run');
});

// #26
it('enforces segregation of duties and locks submitted slips', function () {
    $e = payee();
    $run = runFor('2026-01-01', $this->w->po);
    expect(fn () => submitRun($run, $this->w->po))->toThrow(PayrollRuleViolation::class, 'payroll.errors.sod_submit')
        ->and($this->w->po->can('submit', $run))->toBeFalse()
        ->and($this->w->fin->can('submit', $run))->toBeFalse()
        ->and($this->w->pa->can('submit', $run))->toBeTrue();

    $run = submitRun($run, $this->w->pa);
    $slip = slipOf($e);
    expect($run->status)->toBe(PayrollRunStatus::Submitted)->and($slip->status)->toBe(DocumentStatus::Submitted)
        ->and(fn () => $slip->forceFill(['net_pay' => '1.00'])->save())->toThrow(DocumentLocked::class);
});

// #27
it('blocks submit on a negative net until the employee is excluded', function () {
    payee([], '3000.00');
    $neg = payee([], '1000.00');
    additional($neg->id, 'POTONGAN_LAIN', '5000.00');
    $run = runFor('2026-01-01', $this->w->po);
    expect(slipOf($neg)->has_errors)->toBeTrue()
        ->and(fn () => submitRun($run, $this->w->pa))->toThrow(PayrollRuleViolation::class);

    app(PayrollRunActions::class)->exclude($run->refresh(), $neg->id, 'Semak potongan');
    expect(submitRun($run, $this->w->pa)->status)->toBe(PayrollRunStatus::Submitted);
});

// #28
it('blocks submit with an unverified rate set unless the switch is off and acknowledged', function () {
    payee();
    StatutoryRateSet::query()->where('code', 'T-EPF')->update(['status' => 'unverified']);
    $run = runFor('2026-01-01', $this->w->po);
    expect(fn () => submitRun($run, $this->w->pa))->toThrow(PayrollRuleViolation::class, 'payroll.errors.unverified_rates');

    PayrollSettings::current()->forceFill(['block_unverified_rates' => false])->save();
    expect(fn () => submitRun($run, $this->w->pa))->toThrow(PayrollRuleViolation::class, 'payroll.errors.unverified_ack_required');
    $submitted = app(SubmitPayrollRun::class)->handle($run->refresh(), $this->w->pa, true);
    expect($submitted->unverified_acknowledged)->toBeTrue()
        ->and(DB::table('activity_log')->where('event', 'unverified_acknowledged')->exists())->toBeTrue();
});

// #29
it('cancels a submitted run only when no later month is submitted', function () {
    $e = payee();
    additional($e->id, 'ELAUN_SHIFT', '100.00');
    $jan = payMonth('2026-01-01', $this->w);
    $feb = payMonth('2026-02-01', $this->w);

    expect(fn () => app(CancelPayrollRun::class)->cancel($jan->refresh(), $this->w->pa, 'Silap'))
        ->toThrow(PayrollRuleViolation::class, 'payroll.errors.cancel_later_submitted');

    app(CancelPayrollRun::class)->cancel($feb->refresh(), $this->w->pa, 'Silap');
    app(CancelPayrollRun::class)->cancel($jan->refresh(), $this->w->pa, 'Silap');
    expect($jan->refresh()->status)->toBe(PayrollRunStatus::Cancelled)
        ->and(DB::table('salary_slips')->where('employee_id', $e->id)->where('status', 'cancelled')->count())->toBe(2)
        ->and(DB::table('additional_salary_applications')->count())->toBe(0)
        ->and((string) EmployeeTaxYear::query()->where('employee_id', $e->id)->value('y_normal'))->toBe('0.00')
        ->and(app(PayrollLockQuery::class)->isLocked($e, CarbonImmutable::parse('2026-01-15')))->toBeFalse();
});

// #30
it('withholds two cycles, keeps them out of the bank file and pays a released one separately', function () {
    $e = payee();
    $other = payee();
    app(WithholdingActions::class)->create(['employee_id' => $e->id, 'from_period' => '2026-01-01', 'cycles' => 2, 'reason' => 'Siasatan'], $this->w->po);
    $account = bankAccount();

    $jan = payMonth('2026-01-01', $this->w);
    $file = app(PayrollExports::class)->bankFile($jan, $account);
    $content = Storage::disk('local')->get($file->file_path);
    expect(slipOf($e)->payment_status)->toBe(PaymentStatus::Withheld)
        ->and($content)->not->toContain($e->bank_account_no)->and($content)->toContain($other->bank_account_no);

    app(MarkPayrollRunPaid::class)->handle($jan->refresh(), $this->w->fin, CarbonImmutable::parse('2026-01-31'));
    payMonth('2026-02-01', $this->w);
    expect(slipOf($e)->payment_status)->toBe(PaymentStatus::Withheld);

    $cycle = SalaryWithholdingCycle::query()->whereDate('period', '2026-01-01')->firstOrFail();
    app(WithholdingActions::class)->release($cycle, $this->w->fin, 'Selesai');
    $supp = app(PayrollExports::class)->supplementaryBankFile($jan->refresh(), $account);
    expect(Storage::disk('local')->get($supp->file_path))->toContain($e->bank_account_no)
        ->and($jan->refresh()->status)->toBe(PayrollRunStatus::Paid);
    $janSlip = SalarySlip::query()->where('employee_id', $e->id)->whereDate('period', '2026-01-01')->firstOrFail();
    app(MarkPayrollRunPaid::class)->markSlipPaid($janSlip, CarbonImmutable::parse('2026-02-05'));
    expect($janSlip->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});

// #31
it('enforces the Additional Salary rules', function () {
    $e = payee([], '3000.00', ['ELAUN_TETAP' => '100.00']);
    additional($e->id, 'ELAUN_TETAP', '200.00', ['mode' => 'replace']);
    expect(fn () => additional($e->id, 'ELAUN_TETAP', '300.00', ['mode' => 'replace']))->toThrow(PayrollRuleViolation::class, 'payroll.errors.replace_duplicate');

    $recurring = additional($e->id, 'ELAUN_SHIFT', '50.00', ['kind' => 'recurring', 'period' => null, 'from_period' => '2026-01-01', 'to_period' => '2026-03-01']);
    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $month) {
        payMonth($month, $this->w);
        expect(line(slipOf($e), 'ELAUN_SHIFT'))->toBe('50.00');
    }
    expect($recurring->applications()->count())->toBe(3)
        ->and(line(SalarySlip::query()->where('employee_id', $e->id)->whereDate('period', '2026-01-01')->firstOrFail(), 'ELAUN_TETAP'))->toBe('200.00')
        ->and(fn () => app(AdditionalSalaryActions::class)->cancel($recurring, $this->w->po, 'x'))->toThrow(PayrollRuleViolation::class, 'payroll.errors.additional_consumed');
});

// #32
it('computes the mid-month raise arrear by the increment model', function () {
    $e = payee([], '3000.00');
    payMonth('2026-01-01', $this->w);
    payMonth('2026-02-01', $this->w);
    $raise = raise($e, '2026-03-15', '3600.00');
    payMonth('2026-03-01', $this->w);
    expect(line(slipOf($e), 'GAJI_POKOK'))->toBe('3000.00');

    $arrear = app(ArrearActions::class)->compute($raise, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-01'));
    expect((string) $arrear->total_amount)->toBe('369.23');
    app(ArrearActions::class)->submit($arrear, $this->w->po);

    runFor('2026-04-01', $this->w->po);
    $april = slipOf($e);
    expect(line($april, 'TUNGGAKAN_GAJI'))->toBe('369.23')->and(line($april, 'GAJI_POKOK'))->toBe('3600.00')
        ->and($april->lines()->where('code', 'TUNGGAKAN_GAJI')->value('pcb_type'))->toBe(PcbRemunerationType::Additional);
});

// #33
it('refunds a reversed unpaid-leave day and refuses reversing too many', function () {
    $e = payee([], '3000.00');
    $run = runFor('2026-01-01', $this->w->po);
    app(PayrollRunActions::class)->overrideLwp($run, $e->id, '2', 'Rekod CTG');
    app(StartPayrollGeneration::class)->handle($run->refresh(), $this->w->po, [$e->id]);
    submitRun($run, $this->w->pa);
    $slip = slipOf($e);
    expect((string) $slip->lwp_days)->toBe('2.000');

    $actions = app(CorrectionActions::class);
    $c = $actions->create($slip, CorrectionType::LwpReversal, CarbonImmutable::parse('2026-02-01'), 'Cuti diluluskan semula', '1');
    expect((string) $c->lines()->value('amount'))->toBe('115.38')
        ->and(fn () => $actions->create($slip, CorrectionType::LwpReversal, CarbonImmutable::parse('2026-02-01'), 'x', '1.5'))
        ->toThrow(PayrollRuleViolation::class, 'payroll.errors.correction_days_exceed');
    $actions->submit($c, $this->w->po);
    runFor('2026-02-01', $this->w->po);
    expect(line(slipOf($e), 'PELARASAN_PENDAPATAN'))->toBe('115.38');
});

// #34
it('locks dates only while a submitted slip covers them, and Leave refuses CTG there', function () {
    $e = payee();
    $lock = app(PayrollLockQuery::class);
    $run = runFor('2026-01-01', $this->w->po);
    expect($lock->isLocked($e, CarbonImmutable::parse('2026-01-15')))->toBeFalse();

    leaveWorkflow([leaveStep('Pelulus', $this->w->hr)]);
    $pending = applyLeave($e, seededType('CTG'), '2026-01-27', null, ['reason' => 'Urusan']);

    submitRun($run, $this->w->pa);
    expect($lock->isLocked($e->id, CarbonImmutable::parse('2026-01-15')))->toBeTrue()
        ->and($lock->isLocked($e, CarbonImmutable::parse('2026-02-01')))->toBeFalse()
        ->and(count($lock->lockedDatesIn($e, new DateRange('2026-01-20', '2026-02-10'))))->toBe(12)
        ->and(fn () => approveLeave($pending, $this->w->hr))->toThrow(ApprovalCompletionBlocked::class);

    app(CancelPayrollRun::class)->cancel($run->refresh(), $this->w->pa, 'Silap');
    expect($lock->isLocked($e, CarbonImmutable::parse('2026-01-15')))->toBeFalse();
});

// #34a
it('posts approved OT claims idempotently and hands them back on cancel', function () {
    $fake = new FakeAttendanceForPayroll;
    app()->instance(AttendanceForPayroll::class, $fake);
    $e = payee();
    $fake->overtime[$e->id] = [
        new OvertimePayLine(501, 'OTC-2026-00001', CarbonImmutable::parse('2026-01-01'), component('KERJA_LEBIH_MASA')->id, 240, Money::of('120.50'), ['normal' => 240], ['2026-01-05', '2026-01-06']),
    ];

    $run = runFor('2026-01-01', $this->w->po);
    app(StartPayrollGeneration::class)->handle($run->refresh(), $this->w->po, [$e->id]);
    expect(AdditionalSalary::query()->where('source_ref', 'overtime_claim:501:'.component('KERJA_LEBIH_MASA')->id)->count())->toBe(1)
        ->and(line(slipOf($e), 'KERJA_LEBIH_MASA'))->toBe('120.50');

    submitRun($run, $this->w->pa);
    expect($fake->posted)->toBe([['claims' => [501], 'run' => $run->id]]);

    app(CancelPayrollRun::class)->cancel($run->refresh(), $this->w->pa, 'Silap');
    expect($fake->released)->toBe([$run->id])
        ->and(AdditionalSalary::query()->where('source_type', 'overtime_claim')->where('status', 'cancelled')->count())->toBe(1);
});

it('pays a March OT claim approved after the March run in April', function () {
    $fake = new FakeAttendanceForPayroll;
    app()->instance(AttendanceForPayroll::class, $fake);
    $e = payee();
    payMonth('2026-01-01', $this->w);
    payMonth('2026-02-01', $this->w);
    payMonth('2026-03-01', $this->w);
    $fake->overtime[$e->id] = [new OvertimePayLine(777, 'OTC-2026-00007', CarbonImmutable::parse('2026-03-01'), component('KERJA_LEBIH_MASA')->id, 60, Money::of('30.00'), [], ['2026-03-10'])];

    runFor('2026-04-01', $this->w->po);
    $april = slipOf($e);
    expect(line($april, 'KERJA_LEBIH_MASA'))->toBe('30.00')
        ->and($april->lines()->where('code', 'KERJA_LEBIH_MASA')->value('note'))->toContain('03/2026');
});

// #34b
it('pays an encashment through Additional Salary and reports the payout status', function () {
    $e = payee();
    $encashment = new LeaveEncashment;
    $encashment->forceFill(['employee_id' => $e->id, 'leave_type_id' => seededType('CTG')->id, 'leave_allocation_id' => 0, 'encashment_date' => '2026-01-20',
        'eligible_days' => '2', 'days' => '2', 'rate_basis' => 'org_daily_rate', 'monthly_basic' => '3000', 'daily_rate' => '115.384615',
        'amount' => '230.77', 'status' => 'submitted', 'payout_status' => 'awaiting_payroll', 'purpose' => 'other']);
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $encashment->save();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $id = app(AdditionalPayPort::class)->createForEncashment($encashment);
    expect($id)->not->toBeNull()->and(app(AdditionalPayPort::class)->createForEncashment($encashment))->toBe($id);

    $run = runFor('2026-01-01', $this->w->po);
    expect(line(slipOf($e), 'GANTIAN_CUTI'))->toBe('230.77');
    submitRun($run, $this->w->pa);
    expect($encashment->refresh()->payout_status)->toBe(PayoutStatus::InPayroll)
        ->and(fn () => app(AdditionalPayPort::class)->cancelForEncashment($encashment))->toThrow(PayrollRuleViolation::class);

    app(MarkPayrollRunPaid::class)->handle($run->refresh(), $this->w->fin, CarbonImmutable::parse('2026-01-31'));
    expect($encashment->refresh()->payout_status)->toBe(PayoutStatus::Paid);
});

it('returns an encashment to awaiting payroll when the run is cancelled', function () {
    $e = payee();
    $encashment = new LeaveEncashment;
    $encashment->forceFill(['employee_id' => $e->id, 'leave_type_id' => seededType('CTG')->id, 'leave_allocation_id' => 0, 'encashment_date' => '2026-01-20',
        'eligible_days' => '1', 'days' => '1', 'rate_basis' => 'org_daily_rate', 'monthly_basic' => '3000', 'daily_rate' => '115.384615',
        'amount' => '115.38', 'status' => 'submitted', 'payout_status' => 'awaiting_payroll', 'purpose' => 'other']);
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $encashment->save();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    app(AdditionalPayPort::class)->createForEncashment($encashment);
    $run = payMonth('2026-01-01', $this->w);
    app(CancelPayrollRun::class)->cancel($run->refresh(), $this->w->pa, 'Silap');
    expect($encashment->refresh()->payout_status)->toBe(PayoutStatus::AwaitingPayroll);
});

// #34c
it('provides the contractual wage and basic salary ports', function () {
    $e = payee([], '3000.00', ['ELAUN_TETAP' => '200.00']);
    $none = employee(['date_of_joining' => '2020-01-01']);
    $fake = new FakeUnpaidLeaveQuery;
    $fake->days[$e->id] = ['2026-01-12' => '1'];
    app()->instance(UnpaidLeaveQuery::class, $fake);

    expect((string) app(OtWageBaseProvider::class)->monthlyWageFor($e, CarbonImmutable::parse('2026-01-01')))->toBe('3000.00')
        ->and(app(OtWageBaseProvider::class)->monthlyWageFor($none, CarbonImmutable::parse('2026-01-01')))->toBeNull()
        ->and((string) app(CompensationQuery::class)->monthlyBasic($e, CarbonImmutable::parse('2026-01-15')))->toBe('3000.00')
        ->and(app(CompensationQuery::class)->monthlyBasic($none, CarbonImmutable::parse('2026-01-15')))->toBeNull();
});

// #34d
it('treats unmarked days per setting', function (string $source, string $treatment, string $expected) {
    $fake = new FakeAttendanceForPayroll;
    app()->instance(AttendanceForPayroll::class, $fake);
    PayrollSettings::current()->forceFill(['days_source' => $source, 'unmarked_day_treatment' => $treatment])->save();
    $e = payee([], '2600.00');
    $fake->unmarked[$e->id] = ['2026-01-14'];
    $fake->absences[$e->id] = [];
    runFor('2026-01-01', $this->w->po);
    expect(line(slipOf($e), 'GAJI_POKOK'))->toBe($expected);
})->with([
    'absent' => ['leave_and_attendance', 'absent', '2500.00'],
    'present' => ['leave_and_attendance', 'present', '2600.00'],
    'leave only' => ['leave_only', 'absent', '2600.00'],
]);

// #34e
it('never creates approval requests for payroll documents', function () {
    payee();
    $before = DB::table('approval_requests')->count();
    $run = runFor('2026-01-01', $this->w->po);
    submitRun($run, $this->w->pa);
    expect(DB::table('approval_requests')->count())->toBe($before);
});

// #35
it('rebuilds the tax year as the sum of slips, declarations and opening balances', function () {
    $e = payee([], '4200.00');
    SalaryAssignment::query()->where('employee_id', $e->id)->update(['opening_year' => 2026, 'opening_gross_normal' => '1000.00', 'opening_epf_employee' => '110.00', 'opening_pcb' => '5.00']);
    $faker = fake();
    foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $month) {
        additional($e->id, 'BONUS', (string) $faker->numberBetween(100, 900).'.00', ['period' => $month]);
        $dec = app(TaxDeclarationActions::class)->save(null, ['employee_id' => $e->id, 'type' => 'tp1', 'period' => $month, 'lines' => [['relief_code' => 'lifestyle', 'amount' => '100']]]);
        app(TaxDeclarationActions::class)->submit($dec, $this->w->po);
        payMonth($month, $this->w);
    }
    $row = app(RefreshTaxYear::class)->handle($e->id, 2026);

    $slips = SalarySlip::query()->where('employee_id', $e->id)->where('status', 'submitted')->with(['lines', 'statutory'])->get();
    $taxable = '1000';
    $k = '110';
    $x = '5';
    foreach ($slips as $slip) {
        foreach ($slip->lines as $l) {
            if ($l->taxable && $l->kind->value === 'earning') {
                $taxable = bcadd($taxable, (string) $l->amount, 2);
            }
        }
        $k = bcadd($k, (string) $slip->statutory->epf_employee, 2);
        $x = bcadd($x, (string) $slip->statutory->pcb_net, 2);
    }
    expect(bcadd((string) $row->y_normal, (string) $row->y_additional, 2))->toBe($taxable)
        ->and((string) $row->k)->toBe($k)->and((string) $row->x)->toBe($x)->and((string) $row->lp)->toBe('300.00')
        ->and($row->through_period?->toDateString())->toBe('2026-03-01')
        ->and(app(TaxYearCalculator::class)->totals($e->id, 2026)->k)->toBe($k);
});
