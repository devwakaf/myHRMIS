<?php

// M4 UI: every Payroll / Statutori page renders the right component and props per role, 403/404 for
// everybody else, PDFs and downloads work, the scheduler reminders fire, the demo seeder runs, and
// every translation key used by the Vue pages exists in both languages.

use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Actions\EaFormActions;
use App\Domain\Payroll\Jobs\RemindPayrollNotStarted;
use App\Domain\Payroll\Jobs\RemindStatutoryDue;
use App\Domain\Payroll\Models\EaForm;
use App\Domain\Payroll\Models\OrganisationBankAccount;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\StatutoryRemittance;
use App\Domain\Payroll\Notifications\PayrollNotStartedReminder;
use App\Domain\Payroll\Notifications\SalarySlipIssued;
use App\Domain\Payroll\Notifications\StatutoryDueReminder;
use App\Domain\Payroll\Pdf\PayrollPdf;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Statutory\Models\StatutoryFileFormat;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoPayrollSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PayrollDefaultsSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Storage::fake('local');
    $this->w = payrollWorld('2026-01-10');
    StatutoryFileFormat::query()->update(['effective_from' => '2024-01-01']);
});

/** A submitted January run with one paid-by-bank employee; returns [run, employee]. */
function submittedJanuary(object $w): array
{
    $e = payee([], '3000.00');
    $run = payMonth('2026-01-01', $w);

    return [$run, $e];
}

it('renders the configuration and statutory pages for the Payroll Officer', function (string $url, string $component) {
    payee();
    actingInOrg($this->w->po, $this->w->org)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
})->with([
    ['/payroll/periods', 'Payroll/Periods/Index'],
    ['/statutory/listings/kwsp', 'Statutory/Listings/Show'],
    ['/statutory/listings/hrdc?period=2026-01', 'Statutory/Listings/Show'],
    ['/payroll/reports/register', 'Payroll/Reports/Register'],
]);

it('gives the structure editor the employees and the assignment page the structure components', function () {
    payee();
    $po = actingInOrg($this->w->po, $this->w->org);
    $po->get('/payroll/structures/'.$this->w->structure->id.'/edit')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Structures/Edit')
        ->has('structure.rows')->has('components')->has('employees', 1)->has('options.functions')->where('can.manage', true));
    $po->get('/payroll/assignments')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Assignments/Index')
        ->has('options.structures.0.components')->has('options.employees')->has('assignments.data', 1));
    $po->get('/payroll/declarations')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Declarations/Index')->has('options.relief_codes'));
    $po->get('/payroll/corrections')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Corrections/Index')->has('slips')->has('options.components'));
});

it('shows each role only its Payroll pages', function () {
    payee();
    // Approver: reads configuration and runs, but cannot edit.
    actingInOrg($this->w->pa, $this->w->org)->get('/payroll/components')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', false));
    actingInOrg($this->w->pa, $this->w->org)->get('/payroll/runs')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Runs/Index')->where('can.prepare', false));
    actingInOrg($this->w->pa, $this->w->org)->get('/payroll/slips')->assertOk();
    actingInOrg($this->w->pa, $this->w->org)->get('/statutory/ea')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.publish', true));
    // Finance: runs, slips, statutory files and payments; no configuration.
    actingInOrg($this->w->fin, $this->w->org)->get('/payroll/runs')->assertOk();
    actingInOrg($this->w->fin, $this->w->org)->get('/statutory/files')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Statutory/Files/Index')->where('can.remittances', true));
    actingInOrg($this->w->fin, $this->w->org)->get('/statutory/remittances')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', true));
    actingInOrg($this->w->fin, $this->w->org)->get('/payroll/components')->assertForbidden();
    actingInOrg($this->w->fin, $this->w->org)->get('/payroll/structures')->assertForbidden();
});

it('refuses Kakitangan and HR the pages that are not theirs', function () {
    $e = payee();
    $staff = User::query()->findOrFail($e->user_id);
    foreach (['/payroll/runs', '/payroll/slips', '/payroll/components', '/payroll/structures', '/payroll/assignments', '/payroll/settings', '/payroll/periods',
        '/payroll/additional-salaries', '/payroll/arrears', '/payroll/corrections', '/payroll/withholdings', '/payroll/declarations', '/payroll/reports/register',
        '/statutory/rates', '/statutory/files', '/statutory/remittances', '/statutory/ea', '/statutory/listings/kwsp'] as $url) {
        actingInOrg($staff, $this->w->org)->get($url)->assertForbidden();
    }
    // HR Manager reads configuration and slips but cannot export statutory files or edit rates.
    actingInOrg($this->w->hr, $this->w->org)->get('/payroll/slips')->assertOk();
    actingInOrg($this->w->hr, $this->w->org)->get('/statutory/files')->assertForbidden();
    actingInOrg($this->w->hr, $this->w->org)->get('/statutory/listings/kwsp')->assertForbidden();
    actingInOrg($this->w->po, $this->w->org)->get('/statutory/listings/nope')->assertNotFound();
});

it('lets the employee see only own published slips and EA forms', function () {
    $other = payee([], '3100.00');
    [$run, $mine] = submittedJanuary($this->w);
    $slip = slipOf($mine);
    $user = User::query()->findOrFail($mine->user_id);

    actingInOrg($user, $this->w->org)->get('/me/payslips')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Me/Payslips')->has('slips', 1)->where('slips.0.id', $slip->id));
    actingInOrg($user, $this->w->org)->get('/me/payslips/'.$slip->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Me/PayslipShow')->where('slip.number', $slip->number));
    actingInOrg($user, $this->w->org)->get('/me/payslips/'.$slip->id.'/pdf')->assertOk();
    actingInOrg($user, $this->w->org)->get('/me/payslips/'.slipOf($other)->id.'/pdf')->assertNotFound();
    actingInOrg($user, $this->w->org)->get('/me/ea')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Me/Ea')->has('forms', 0));

    $form = app(EaFormActions::class)->generateFor($mine->id, 2026);
    actingInOrg($user, $this->w->org)->get('/me/ea/'.$form->id.'/pdf')->assertNotFound(); // not published yet
    app(EaFormActions::class)->publish($form, $this->w->pa);
    actingInOrg($user, $this->w->org)->get('/me/ea')->assertInertia(fn (Assert $p) => $p->has('forms', 1));
    $response = actingInOrg($user, $this->w->org)->get('/me/ea/'.$form->id.'/pdf')->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
    expect($run->status->value)->toBe('submitted');
});

it('renders the run wizard with the right props and lets only the Approver submit', function () {
    payee();
    $po = actingInOrg($this->w->po, $this->w->org);
    $po->post('/payroll/runs', ['period' => '2026-01-01'])->assertRedirect();
    $run = PayrollRun::query()->firstOrFail();
    $po->get('/payroll/runs')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Runs/Index')->has('runs.data', 1)->where('can.prepare', true)->has('options.branches'));
    $po->get('/payroll/runs/'.$run->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Runs/Show')->has('preview.eligible', 1)->where('can.submit', false)->where('can.prepare', true));
    $po->post('/payroll/runs/'.$run->id.'/generate')->assertRedirect();
    $po->getJson('/payroll/runs/'.$run->id.'/progress')->assertOk()->assertJsonStructure(['status', 'processing_state', 'counts', 'employee_count']);

    actingInOrg($this->w->pa, $this->w->org)->get('/payroll/runs/'.$run->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.submit', true)->where('can.prepare', false)->where('run.status', 'slips_generated')
        ->has('employees', 1)->has('rate_sets')->has('component_totals'));
    actingInOrg($this->w->fin, $this->w->org)->get('/payroll/runs/'.$run->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.mark_paid', true)->where('can.submit', false));
});

it('does the draft overrides over HTTP, discards slips and shows overrides on the run', function () {
    $e = payee();
    $po = actingInOrg($this->w->po, $this->w->org);
    $po->post('/payroll/runs', ['period' => '2026-01-01'])->assertRedirect();
    $run = PayrollRun::query()->firstOrFail();
    $po->post('/payroll/runs/'.$run->id.'/generate')->assertRedirect();
    $po->post('/payroll/runs/'.$run->id.'/employees/'.$e->id.'/override-pcb', ['amount' => '12.30', 'reason' => 'Uji'])->assertRedirect();
    $po->get('/payroll/runs/'.$run->id)->assertInertia(fn (Assert $p) => $p->where('employees.0.pcb_override_amount', '12.30'));
    $po->post('/payroll/runs/'.$run->id.'/employees/'.$e->id.'/override-pcb', ['amount' => '5'])->assertSessionHasErrors('reason');
    $po->post('/payroll/runs/'.$run->id.'/discard')->assertRedirect();
    expect($run->refresh()->status->value)->toBe('draft');
});

it('produces the slip PDF (BM, RM format, bundled font) and caches it on the slip', function () {
    [$run, $e] = submittedJanuary($this->w);
    $slip = slipOf($e);
    $response = actingInOrg($this->w->pa, $this->w->org)->get('/payroll/slips/'.$slip->id.'/pdf')->assertOk();
    $pdf = $response->baseResponse instanceof StreamedResponse ? Storage::disk('local')->get($slip->refresh()->pdf_path) : $response->getContent();

    expect($pdf)->toStartWith('%PDF')->and($slip->refresh()->pdf_path)->not->toBeNull()
        ->and($slip->getAttribute('pdf_template_version'))->toBe(PayrollPdf::TEMPLATE_VERSION)
        ->and($pdf)->toContain('DejaVuSans')->and($pdf)->not->toContain('http://')->and($pdf)->not->toContain('/URI');
    expect(PayrollPdf::money('1234.5'))->toBe('RM 1,234.50')->and(PayrollPdf::money('-12'))->toBe('-RM 12.00')->and(PayrollPdf::date('2026-01-05'))->toBe('05/01/2026');

    // a second request reuses the cached file
    $path = $slip->pdf_path;
    $before = Storage::disk('local')->lastModified($path);
    actingInOrg($this->w->pa, $this->w->org)->get('/payroll/slips/'.$slip->id.'/pdf')->assertOk();
    expect($slip->refresh()->pdf_path)->toBe($path)->and(Storage::disk('local')->lastModified($path))->toBe($before);
});

it('attaches the slip PDF to the e-mail and lets HR or Payroll trigger it again', function () {
    Notification::fake();
    [$run, $e] = submittedJanuary($this->w);
    $slip = slipOf($e);
    $user = User::query()->findOrFail($e->user_id);

    Notification::assertSentTo($user, SalarySlipIssued::class);
    $mail = (new SalarySlipIssued($slip->id, 'Januari 2026', 'Org'))->toMail($user);
    expect($mail->rawAttachments ?? [])->toBe([])->and(count($mail->attachments))->toBe(1)->and($mail->attachments[0]['options']['mime'])->toBe('application/pdf');

    actingInOrg($this->w->po, $this->w->org)->post('/payroll/slips/'.$slip->id.'/resend')->assertRedirect()->assertSessionHas('success');
    actingInOrg($this->w->hr, $this->w->org)->post('/payroll/slips/'.$slip->id.'/resend')->assertRedirect();
    actingInOrg($user, $this->w->org)->post('/payroll/slips/'.$slip->id.'/resend')->assertForbidden();
});

it('downloads the register as XLSX and CSV, the listings as PDF/XLSX/CSV and the cash list as PDF', function () {
    $cash = payee(['salary_payment_mode' => 'cash'], '2500.00');
    payee([], '3000.00');
    $run = payMonth('2026-01-01', $this->w);

    $fin = actingInOrg($this->w->fin, $this->w->org);
    $xlsx = $fin->get('/payroll/reports/register?export=xlsx&payroll_run_id='.$run->id)->assertOk();
    expect($xlsx->baseResponse->headers->get('content-disposition'))->toContain('.xlsx');
    $csv = $fin->get('/payroll/reports/register?export=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('Gaji Pokok')->and($csv)->toContain('Jumlah');
    $fin->get('/payroll/reports/register')->assertInertia(fn (Assert $p) => $p->has('columns')->has('rows', 2)->has('totals.net_pay'));

    foreach (['kwsp', 'perkeso', 'sip', 'pcb', 'hrdc'] as $kind) {
        $fin->get("/statutory/listings/$kind?period=2026-01")->assertOk()->assertInertia(fn (Assert $p) => $p->component('Statutory/Listings/Show')->where('kind', $kind)->has('columns')->has('totals'));
        $pdf = $fin->get("/statutory/listings/$kind/export?period=2026-01&format=pdf")->assertOk();
        expect($pdf->getContent())->toStartWith('%PDF');
        $fin->get("/statutory/listings/$kind/export?period=2026-01&format=xlsx")->assertOk();
    }
    $listing = $fin->get('/statutory/listings/kwsp/export?period=2026-01&format=csv')->assertOk();
    expect(file_get_contents($listing->baseResponse->getFile()->getPathname()))->toContain('No. Kakitangan');

    $list = $fin->get('/payroll/runs/'.$run->id.'/cash-list/pdf')->assertOk();
    expect($list->getContent())->toStartWith('%PDF');
    actingInOrg($this->w->po, $this->w->org)->get('/payroll/runs/'.$run->id.'/cash-list/pdf')->assertForbidden();
    expect($cash->employee_no)->not->toBeEmpty();
});

it('generates the bank, summary and statutory files and downloads them', function () {
    [$run] = submittedJanuary($this->w);
    $account = new OrganisationBankAccount(['bank_id' => Bank::query()->value('id'), 'account_no' => '1234567890', 'account_name' => 'Org']);
    $account->save();
    $fin = actingInOrg($this->w->fin, $this->w->org);

    $fin->post('/payroll/runs/'.$run->id.'/bank-file', ['account_id' => $account->id])->assertRedirect()->assertSessionHas('success');
    $fin->post('/payroll/runs/'.$run->id.'/summary-csv')->assertRedirect();
    $fin->post('/statutory/files/perkeso', ['period' => '2026-01'])->assertRedirect();
    $fin->post('/statutory/files/kwsp', ['period' => '2026-01'])->assertRedirect();

    $files = PayrollFile::query()->get();
    expect($files->count())->toBeGreaterThanOrEqual(4);
    foreach ($files as $file) {
        $fin->get('/payroll/files/'.$file->id.'/download')->assertOk();
    }
    $fin->get('/statutory/files?period=2026-01')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Statutory/Files/Index')->has('files')
        ->where('files', fn ($f) => collect($f)->contains(fn ($x) => $x['kind'] === 'kwsp' && $x['format_unverified'] === true)));
    actingInOrg($this->w->po, $this->w->org)->post('/payroll/runs/'.$run->id.'/bank-file', ['account_id' => $account->id])->assertForbidden();
});

it('downloads EA and PCB 2(II) PDFs for authorised roles only', function () {
    [$run, $e] = submittedJanuary($this->w);
    $form = app(EaFormActions::class)->generateFor($e->id, 2026);
    StatutoryRemittance::query()->create(['scheme' => 'pcb', 'period' => '2026-01-01', 'amount' => '10.00', 'paid_on' => '2026-02-10', 'receipt_no' => 'R1']);

    $pa = actingInOrg($this->w->pa, $this->w->org);
    expect($pa->get('/statutory/ea/'.$form->id.'/pdf')->assertOk()->getContent())->toStartWith('%PDF');
    expect($pa->get('/statutory/pcb2ii/'.$e->id.'/pdf?year=2026')->assertOk()->getContent())->toStartWith('%PDF');
    $pa->get('/statutory/ea?year=2026')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Statutory/Ea/Index')->has('forms', 1)->where('forms.0.employee', $e->full_name));
    actingInOrg($this->w->hr, $this->w->org)->get('/statutory/ea/'.$form->id.'/pdf')->assertForbidden();
    expect(EaForm::query()->count())->toBe(1);
});

it('assigns a structure to many employees in one go and reports the failures', function () {
    $a = employee(['date_of_joining' => '2020-01-01']);
    $b = employee(['date_of_joining' => '2026-03-01']); // joins after the effective date -> fails
    $po = actingInOrg($this->w->po, $this->w->org);
    $po->post('/payroll/assignments/bulk', [
        'salary_structure_id' => $this->w->structure->id, 'effective_from' => '2026-01-01', 'reason' => 'increment', 'submit' => true,
        'items' => [['employee_id' => $a->id, 'base_salary' => '3100'], ['employee_id' => $b->id, 'base_salary' => '2900']],
    ])->assertRedirect()->assertSessionHas('success')->assertSessionHas('warnings');
    expect(SalaryAssignment::query()->where('employee_id', $a->id)->where('status', 'submitted')->count())->toBe(1)
        ->and(SalaryAssignment::query()->where('employee_id', $b->id)->count())->toBe(0);
    actingInOrg($this->w->pa, $this->w->org)->post('/payroll/assignments/bulk', ['salary_structure_id' => 1, 'effective_from' => '2026-01-01', 'items' => []])->assertForbidden();
});

it('serves the Payroll tab data of Butiran Kakitangan to authorised roles only', function () {
    [$run, $e] = submittedJanuary($this->w);
    actingInOrg($this->w->hr, $this->w->org)->getJson('/payroll/employees/'.$e->id.'/summary')->assertOk()
        ->assertJsonPath('employee.id', $e->id)->assertJsonCount(1, 'slips')->assertJsonPath('can.config', true);
    actingInOrg($this->w->fin, $this->w->org)->getJson('/payroll/employees/'.$e->id.'/summary')->assertOk()->assertJsonPath('can.config', false)->assertJsonPath('assignments', []);
    actingInOrg(User::query()->findOrFail($e->user_id), $this->w->org)->getJson('/payroll/employees/'.$e->id.'/summary')->assertForbidden();
    actingInOrg($this->w->hr, $this->w->org)->get('/employees/'.$e->id)->assertInertia(fn (Assert $p) => $p->where('can.payroll', true));
    actingInOrg(User::query()->findOrFail($e->user_id), $this->w->org)->get('/employees/'.$e->id)->assertInertia(fn (Assert $p) => $p->where('can.payroll', false));
});

it('shows the Tempoh Gaji with the lock state of every month', function () {
    submittedJanuary($this->w);
    actingInOrg($this->w->po, $this->w->org)->get('/payroll/periods')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Payroll/Periods/Index')
        ->where('next_period', '2026-02')->where('periods.0.period', '2026-02')->where('periods.0.locked', false)->where('periods.1.period', '2026-01')->where('periods.1.locked', true)
        ->where('periods.1.run.status', 'submitted'));
});

it('shows rate sets with their status and applies the two-person rule over HTTP', function () {
    $sa = superAdmin();
    $set = StatutoryRateSet::query()->where('code', 'T-EIS')->firstOrFail();
    $set->forceFill(['status' => 'unverified', 'created_by' => $sa->id, 'verified_at' => null])->save();
    actingInOrg($this->w->po, $this->w->org)->get('/statutory/rates')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Statutory/Rates/Index')->has('sets')->where('can.manage', false)->has('file_formats'));
    actingInOrg($sa, $this->w->org)->get('/statutory/rates/'.$set->id)->assertInertia(fn (Assert $p) => $p->where('set.status', 'unverified')->where('can.verify', false));
    $other = superAdmin();
    actingInOrg($other, $this->w->org)->get('/statutory/rates/'.$set->id)->assertInertia(fn (Assert $p) => $p->where('can.verify', true));
});

it('reminds about unrecorded statutory payments on the 10th and the 15th', function () {
    Notification::fake();
    payee();
    payMonth('2026-01-01', $this->w);
    // January is paid by an earlier call in this test; nothing yet recorded.
    (new RemindStatutoryDue(CarbonImmutable::parse('2026-02-09')))->handle(app(OrganisationMembership::class));
    Notification::assertNotSentTo($this->w->po, StatutoryDueReminder::class);

    (new RemindStatutoryDue(CarbonImmutable::parse('2026-02-10')))->handle(app(OrganisationMembership::class));
    Notification::assertSentTo($this->w->po, StatutoryDueReminder::class, fn (StatutoryDueReminder $n) => $n->kind() === 'statutory_due' && in_array('hrdc', $n->schemes, true));
    Notification::assertSentTo($this->w->fin, StatutoryDueReminder::class);
    Notification::assertNotSentTo($this->w->hr, StatutoryDueReminder::class);

    // idempotent for the same day
    (new RemindStatutoryDue(CarbonImmutable::parse('2026-02-10')))->handle(app(OrganisationMembership::class));
    Notification::assertSentToTimes($this->w->po, StatutoryDueReminder::class, 1);

    // recorded payments silence the reminder on the 15th
    foreach (['kwsp', 'perkeso', 'pcb', 'hrdc'] as $scheme) {
        StatutoryRemittance::query()->create(['scheme' => $scheme, 'period' => '2026-01-01', 'amount' => '1.00', 'paid_on' => '2026-02-12']);
    }
    (new RemindStatutoryDue(CarbonImmutable::parse('2026-02-15')))->handle(app(OrganisationMembership::class));
    Notification::assertSentToTimes($this->w->po, StatutoryDueReminder::class, 1);

    $mail = (new StatutoryDueReminder('2026-01', ['kwsp', 'pcb'], true))->toMail($this->w->po);
    expect($mail->subject)->toContain('Januari 2026')->and($mail->introLines[0])->toContain('KWSP, PCB');
});

it('reminds the Payroll Officer when the month has no run, in BM and EN', function () {
    Notification::fake();
    (new RemindPayrollNotStarted(CarbonImmutable::parse('2026-01-20')))->handle(app(OrganisationMembership::class));
    Notification::assertNothingSent();
    (new RemindPayrollNotStarted(CarbonImmutable::parse('2026-01-25')))->handle(app(OrganisationMembership::class));
    Notification::assertSentTo($this->w->po, PayrollNotStartedReminder::class);
    Notification::assertNotSentTo($this->w->pa, PayrollNotStartedReminder::class);

    (new RemindPayrollNotStarted(CarbonImmutable::parse('2026-01-31')))->handle(app(OrganisationMembership::class));
    Notification::assertSentToTimes($this->w->po, PayrollNotStartedReminder::class, 2);

    app()->setLocale('en');
    expect((new PayrollNotStartedReminder('2026-01'))->toMail($this->w->po)->subject)->toBe('Payroll run for January 2026 not started');
    app()->setLocale('ms');
    expect((new PayrollNotStartedReminder('2026-01'))->toMail($this->w->po)->subject)->toBe('Proses Gaji Januari 2026 belum dimulakan');

    // a run (even a draft) silences it
    runFor('2026-02-01', $this->w->po);
    Notification::fake();
    (new RemindPayrollNotStarted(CarbonImmutable::parse('2026-02-25')))->handle(app(OrganisationMembership::class));
    Notification::assertNothingSent();
});

it('registers both reminder jobs in the scheduler', function () {
    $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();
    expect($names)->toContain('payroll:remind-statutory-due')->and($names)->toContain('payroll:remind-not-started');
});

it('seeds the Payroll defaults from DatabaseSeeder and a demo run through to Dihantar', function () {
    // fresh world: reference data, demo organisations, payroll defaults, then the demo payroll
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->seed(PayrollDefaultsSeeder::class);
    $this->seed(DemoPayrollSeeder::class);

    $org = Organisation::query()->where('short_code', 'SDT')->firstOrFail();
    inOrg($org);
    $run = PayrollRun::query()->first();
    expect($run)->not->toBeNull()->and($run->status->value)->toBe('submitted')->and(SalarySlip::query()->count())->toBeGreaterThan(5)
        ->and(StatutoryRateSet::query()->whereIn('code', ['EPF-2025-10', 'SOCSO-2026-06', 'EIS-2024-10'])->where('is_placeholder', true)->count())->toBe(3)
        ->and(User::query()->where('email', 'payroll.approver@myhrmis.test')->exists())->toBeTrue();

    // idempotent
    $this->seed(DemoPayrollSeeder::class);
    expect(PayrollRun::query()->count())->toBe(1);
})->group('slow');

it('uses a translation for every payroll and statutory key the Vue pages reference, in both languages', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));
    $missing = [];
    foreach ($files as $file) {
        $path = $file->getPathname();
        if (! preg_match('/\.(vue|ts)$/', $path) || str_contains($path, 'wayfinder') || str_contains($path, DIRECTORY_SEPARATOR.'actions'.DIRECTORY_SEPARATOR)) {
            continue;
        }
        preg_match_all('/[\'"`]((?:payroll|statutory)\.[A-Za-z0-9_.\-]+)[\'"`]/', (string) file_get_contents($path), $m);
        foreach (array_unique($m[1]) as $key) {
            if (str_ends_with($key, '.') || in_array($key, ['payroll.config.manage', 'payroll.config.view', 'payroll.run.prepare', 'payroll.run.submit', 'payroll.run.mark-paid', 'payroll.slip.view-all',
                'payroll.adjustments.manage', 'payroll.adjustments.view', 'payroll.withholding.manage', 'payroll.withholding.release', 'statutory.files.export', 'statutory.remittances.manage',
                'statutory.ea.publish'], true)) {
                continue; // permission names, not translation keys
            }
            foreach (['ms', 'en'] as $locale) {
                if (! Lang::has($key, $locale, false)) {
                    $missing[] = "$locale:$key (".basename($path).')';
                }
            }
        }
    }
    expect($missing)->toBe([]);
});
