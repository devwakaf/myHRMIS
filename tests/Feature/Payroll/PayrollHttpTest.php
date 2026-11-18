<?php

// Payroll / Statutori HTTP layer: placeholder pages render real props, role gates,
// segregation of duties over HTTP (spec-04 §1, §1.5; case 26 HTTP half).

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->w = payrollWorld('2026-01-10');
});

it('renders every Payroll page for the Payroll Officer', function (string $url, string $component) {
    payee();
    actingInOrg($this->w->po, $this->w->org)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
})->with([
    ['/payroll/components', 'Payroll/Components/Index'],
    ['/payroll/structures', 'Payroll/Structures/Index'],
    ['/payroll/structures/create', 'Payroll/Structures/Edit'],
    ['/payroll/assignments', 'Payroll/Assignments/Index'],
    ['/payroll/settings', 'Payroll/Settings'],
    ['/payroll/runs', 'Payroll/Runs/Index'],
    ['/payroll/slips', 'Payroll/Slips/Index'],
    ['/payroll/additional-salaries', 'Payroll/AdditionalSalaries/Index'],
    ['/payroll/arrears', 'Payroll/Arrears/Index'],
    ['/payroll/corrections', 'Payroll/Corrections/Index'],
    ['/payroll/withholdings', 'Payroll/Withholdings/Index'],
    ['/payroll/declarations', 'Payroll/Declarations/Index'],
    ['/payroll/reports/register', 'Payroll/Reports/Register'],
    ['/statutory/rates', 'Statutory/Rates/Index'],
    ['/statutory/files', 'Statutory/Files/Index'],
    ['/statutory/remittances', 'Statutory/Remittances/Index'],
    ['/statutory/ea', 'Statutory/Ea/Index'],
]);

it('drives the run wizard over HTTP with role gates', function () {
    $e = payee();
    $po = actingInOrg($this->w->po, $this->w->org);
    $po->post('/payroll/runs', ['period' => '2026-01-01'])->assertRedirect();
    $run = PayrollRun::query()->firstOrFail();

    $po->get('/payroll/runs/'.$run->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Runs/Show')
        ->has('preview.eligible', 1)->where('run.status', 'draft')->where('can.submit', false));
    $po->post('/payroll/runs/'.$run->id.'/generate')->assertRedirect()->assertSessionHas('success');
    $po->getJson('/payroll/runs/'.$run->id.'/progress')->assertOk()->assertJsonPath('status', 'slips_generated');

    actingInOrg($this->w->po, $this->w->org)->post('/payroll/runs/'.$run->id.'/submit')->assertForbidden();
    actingInOrg($this->w->fin, $this->w->org)->post('/payroll/runs/'.$run->id.'/submit')->assertForbidden();
    actingInOrg($this->w->pa, $this->w->org)->post('/payroll/runs/'.$run->id.'/submit')->assertRedirect()->assertSessionHas('success');
    expect($run->refresh()->status->value)->toBe('submitted');

    $slip = slipOf($e);
    actingInOrg($this->w->pa, $this->w->org)->get('/payroll/slips/'.$slip->id)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Payroll/Slips/Show')->where('slip.net_pay', (string) $slip->net_pay)->where('can.trace', true));
    actingInOrg($this->w->fin, $this->w->org)->post('/payroll/runs/'.$run->id.'/mark-paid', ['paid_on' => '2026-01-31'])->assertRedirect();
    expect($run->refresh()->status->value)->toBe('paid');

    $user = User::query()->findOrFail($e->user_id);
    actingInOrg($user, $this->w->org)->get('/me/payslips')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Me/Payslips')->has('slips', 1));
    actingInOrg($user, $this->w->org)->get('/payroll/runs')->assertForbidden();
});

it('validates structures over HTTP and reports cycles in BM', function () {
    $po = actingInOrg($this->w->po, $this->w->org);
    $po->postJson('/payroll/structures/dry-run', ['code' => 'X1', 'name_ms' => 'X', 'rows' => [
        ['salary_component_id' => component('GAJI_POKOK')->id],
        ['salary_component_id' => component('ELAUN_TETAP')->id, 'calculation_override' => 'formula', 'formula' => 'KWSP_PEKERJA * 2'],
        ['salary_component_id' => component('KWSP_PEKERJA')->id],
    ]])->assertStatus(422)->assertJsonPath('errors.rows.0', 'Rujukan berkitar dikesan: ELAUN_TETAP → KWSP_PEKERJA → [KWSP] → ELAUN_TETAP.');

    // ELAUN_TETAP is an EPF wage: referencing KWSP_PEKERJA is circular.
    $po->post('/payroll/structures', ['code' => 'X2', 'name_ms' => 'X', 'rows' => [
        ['salary_component_id' => component('GAJI_POKOK')->id],
        ['salary_component_id' => component('ELAUN_TETAP')->id, 'calculation_override' => 'formula', 'formula' => 'KWSP_PEKERJA * 2'],
        ['salary_component_id' => component('KWSP_PEKERJA')->id],
    ]])->assertSessionHasErrors(['rows' => 'Rujukan berkitar dikesan: ELAUN_TETAP → KWSP_PEKERJA → [KWSP] → ELAUN_TETAP.']);

    $po->post('/payroll/structures', ['code' => 'X3', 'name_ms' => 'X', 'rows' => [
        ['salary_component_id' => component('GAJI_POKOK')->id],
        ['salary_component_id' => component('ELAUN_PERUMAHAN')->id, 'calculation_override' => 'formula', 'formula' => 'GAJI_POKOK * 10%'],
    ]])->assertSessionHasErrors('rows.1.formula');

    $po->post('/payroll/structures', ['code' => 'X4', 'name_ms' => 'X', 'rows' => [
        ['salary_component_id' => component('GAJI_POKOK')->id],
        ['salary_component_id' => component('ELAUN_PERUMAHAN')->id, 'calculation_override' => 'formula', 'formula' => 'FULL(GAJI_POKOK) * 10%'],
    ]])->assertSessionHasNoErrors()->assertRedirect();

    $po->postJson('/payroll/formula/test', ['formula' => 'SQRT(4)'])->assertStatus(422)->assertJsonPath('message', "Fungsi 'SQRT' tidak dibenarkan dalam formula.");
});

it('limits statutory rate maintenance to Super Admin', function () {
    $set = StatutoryRateSet::query()->where('code', 'T-EIS')->firstOrFail();
    actingInOrg($this->w->po, $this->w->org)->get('/statutory/rates/'.$set->id)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Statutory/Rates/Show')->has('tables.eis_bands')->where('can.manage', false));
    actingInOrg($this->w->po, $this->w->org)->post('/statutory/rates/'.$set->id.'/copy', ['code' => 'EIS-X', 'effective_from' => '2027-01-01'])->assertForbidden();
    actingInOrg(superAdmin(), $this->w->org)->post('/statutory/rates/'.$set->id.'/copy', ['code' => 'EIS-X', 'effective_from' => '2027-01-01'])->assertRedirect();
    expect(StatutoryRateSet::query()->where('code', 'EIS-X')->value('status'))->not->toBeNull();
});
