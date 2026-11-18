<?php

// spec-04 §8.4 — files and reports (cases 36–42) + rate-set lifecycle.

use App\Domain\Core\Models\Bank;
use App\Domain\Payroll\Actions\EaFormActions;
use App\Domain\Payroll\Actions\TaxDeclarationActions;
use App\Domain\Payroll\Actions\WithholdingActions;
use App\Domain\Payroll\Exports\PayrollExports;
use App\Domain\Payroll\Models\OrganisationBankAccount;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Notifications\SalarySlipIssued;
use App\Domain\Payroll\Services\SlipPublisher;
use App\Domain\Statutory\Actions\RateSetActions;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Exceptions\StatutoryRateException;
use App\Domain\Statutory\Files\StatutoryFiles;
use App\Domain\Statutory\Models\StatutoryFileFormat;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\RateCsvImporter;
use App\Domain\Statutory\Services\StatutoryFixtures;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->w = payrollWorld('2026-01-10');
    StatutoryFileFormat::query()->update(['effective_from' => '2024-01-01']);
});

function fileLines(PayrollFile $file): array
{
    return array_values(array_filter(explode("\r\n", (string) Storage::disk('local')->get($file->file_path)), fn ($l) => $l !== ''));
}

// #36
it('writes the PERKESO 278-character file', function () {
    $local = payee(['employee_no' => 'K001'], '3250.00');
    $foreign = payee(['employee_no' => 'K002', 'identity_type' => 'passport', 'ic_no' => null, 'citizenship_status' => 'foreign',
        'passport_no' => 'A7777777', 'passport_country' => 'ID', 'perkeso_no' => 'SSFW1234567', 'work_pass_expiry' => '2027-06-30'], '2000.00');
    payMonth('2026-01-01', $this->w);

    $lines = fileLines(app(StatutoryFiles::class)->perkeso(CarbonImmutable::parse('2026-01-01')));
    expect($lines)->toHaveCount(2);
    foreach ($lines as $line) {
        expect(strlen($line))->toBe(278);
    }
    $byId = collect($lines)->keyBy(fn ($l) => trim(substr($l, 32, 12)));
    expect(substr($byId[$local->ic_no], 200, 14))->toBe('00000000325000')
        ->and(substr($byId[$local->ic_no], 194, 6))->toBe('012026')
        ->and(substr($byId[$local->ic_no], 214, 6))->toBe('005685')
        ->and($byId->has('SSFW1234567'))->toBeTrue();
});

// #37
it('writes CP39 with matching header totals and refuses long employee numbers', function () {
    payee(['employee_no' => 'A1'], '8000.00');
    payee(['employee_no' => 'A2'], '6000.00', ['ELAUN_TETAP' => '710.80']);
    payee(['employee_no' => 'A3'], '3000.00');
    payMonth('2026-01-01', $this->w);

    $file = app(StatutoryFiles::class)->cp39(CarbonImmutable::parse('2026-01-01'));
    $lines = fileLines($file);
    $header = $lines[0];
    $details = array_slice($lines, 1);
    expect(strlen($header))->toBe(57)->and($details)->toHaveCount(2);
    $sum = '0';
    foreach ($details as $d) {
        expect(strlen($d))->toBe(136);
        $sum = bcadd($sum, ltrim(substr($d, 110, 8), '0'), 2);
    }
    expect(bcadd(ltrim(substr($header, 27, 10), '0'), '0', 2))->toBe($sum)->and($sum)->toBe('799.90')
        ->and(substr($header, 37, 5))->toBe('00002')
        ->and($file->file_name)->toMatch('/^\d{10}01_2026\.txt$/');

    payee(['employee_no' => 'PANJANG-12345'], '9000.00');
    payMonth('2026-02-01', $this->w);
    expect(fn () => app(StatutoryFiles::class)->cp39(CarbonImmutable::parse('2026-02-01')))->toThrow(ValidationException::class);
});

it('exports the KWSP file with the contribution month and an unverified flag', function () {
    $e = payee([], '3250.00');
    payMonth('2026-01-01', $this->w);
    $file = app(StatutoryFiles::class)->kwsp(CarbonImmutable::parse('2026-01-01'));
    $lines = fileLines($file);
    expect($file->format_unverified)->toBeTrue()->and($lines[1])->toContain('424.00')->and($lines[1])->toContain('359.00')
        ->and($lines[1])->toEndWith('022026');
});

// #38
it('keeps withheld and cash slips out of the bank file and reconciles the summary CSV', function () {
    $bank = payee([], '3000.00');
    $cash = payee(['salary_payment_mode' => 'cash'], '2500.00');
    $held = payee([], '2800.00');
    app(WithholdingActions::class)->create(['employee_id' => $held->id, 'from_period' => '2026-01-01', 'cycles' => 1, 'reason' => 'x'], $this->w->po);
    $run = payMonth('2026-01-01', $this->w);
    $account = new OrganisationBankAccount(['bank_id' => Bank::query()->value('id'), 'account_no' => '1234567890', 'account_name' => 'Org']);
    $account->save();

    $lines = fileLines(app(PayrollExports::class)->bankFile($run, $account));
    expect($lines)->toHaveCount(3)->and($lines[1])->toContain($bank->bank_account_no)
        ->and(implode("\n", $lines))->not->toContain((string) $held->bank_account_no)
        ->and(app(PayrollExports::class)->cashChequeList($run))->toHaveCount(1);

    $summary = array_map('str_getcsv', fileLines(app(PayrollExports::class)->summaryCsv($run)));
    $earn = $ded = $net = '0';
    foreach (array_slice($summary, 1) as $row) {
        match ($row[6]) {
            'earning' => $earn = bcadd($earn, $row[7], 2),
            'deduction' => $ded = bcadd($ded, $row[7], 2),
            'net_pay' => $net = bcadd($net, $row[7], 2),
            default => null,
        };
    }
    expect(bcsub($earn, $ded, 2))->toBe($net)->and($net)->toBe((string) $run->refresh()->net_total);
});

// #39
it('builds the Salary Register with non-zero component columns and totals', function () {
    $a = payee([], '3000.00');
    $b = payee([], '4000.00', ['ELAUN_TETAP' => '250.00']);
    payMonth('2026-01-01', $this->w);
    $data = app(PayrollExports::class)->register(['period_from' => '2026-01-01', 'period_to' => '2026-01-31']);

    $codes = array_column($data['columns'], 'code');
    expect($codes)->toContain('GAJI_POKOK')->toContain('ELAUN_TETAP')->not->toContain('ZAKAT')->not->toContain('CP38')->not->toContain('KWSP_MAJIKAN')
        ->and($data['rows'])->toHaveCount(2)
        ->and($data['totals']['GAJI_POKOK'])->toBe('7000.00')->and($data['totals']['ELAUN_TETAP'])->toBe('250.00')
        ->and($data['totals']['net_pay'])->toBe(bcadd((string) slipOf($a)->net_pay, (string) slipOf($b)->net_pay, 2));
});

// #40
it('freezes EA figures from slips and same-employer opening, excluding TP3 income', function () {
    $e = payee(['date_of_joining' => '2025-06-01'], '5000.00');
    SalaryAssignment::query()->where('employee_id', $e->id)->update(['opening_year' => 2026, 'opening_gross_normal' => '1000.00', 'opening_pcb' => '20.00', 'opening_epf_employee' => '110.00']);
    $dec = app(TaxDeclarationActions::class)->save(null, ['employee_id' => $e->id, 'type' => 'tp3', 'period' => '2026-01-01', 'prev_gross_normal' => '9000', 'prev_pcb' => '300', 'prev_epf' => '990']);
    app(TaxDeclarationActions::class)->submit($dec, $this->w->po);
    payMonth('2026-01-01', $this->w);
    payMonth('2026-02-01', $this->w);

    $form = app(EaFormActions::class)->generateFor($e->id, 2026);
    $slips = SalarySlip::query()->where('employee_id', $e->id)->where('status', 'submitted')->with('statutory')->get();
    $pcb = '20.00';
    foreach ($slips as $s) {
        $pcb = bcadd($pcb, (string) $s->statutory->pcb_net, 2);
    }
    expect($form->data['remuneration']['b1a_salary'])->toBe('11000.00')
        ->and($form->data['pcb'])->toBe($pcb)
        ->and($form->data['epf_employee'])->toBe(bcadd('110.00', bcadd((string) $slips[0]->statutory->epf_employee, (string) $slips[1]->statutory->epf_employee, 2), 2));

    app(EaFormActions::class)->publish($form, $this->w->pa);
    $again = app(EaFormActions::class)->generateFor($e->id, 2026);
    expect($again->id)->not->toBe($form->id)->and($form->refresh()->status->value)->toBe('superseded');
});

// #41
it('protects slips: PDF password, amount-free e-mail, own published slips only', function () {
    Notification::fake();
    PayrollSettings::current()->forceFill(['pdf_password_mode' => 'ic_no'])->save();
    $mine = payee([], '3000.00');
    $other = payee([], '3100.00');
    payMonth('2026-01-01', $this->w);
    $slip = slipOf($mine);

    expect(app(SlipPublisher::class)->pdfPassword($slip))->toBe($mine->ic_no);
    Notification::assertSentTo(User::query()->findOrFail($mine->user_id), SalarySlipIssued::class, function (SalarySlipIssued $n, array $channels, User $user) use ($slip) {
        $mail = $n->toMail($user);
        $text = implode(' ', array_merge([$mail->subject], $mail->introLines, $mail->outroLines));

        return ! str_contains($text, (string) $slip->net_pay) && str_contains($mail->subject, 'Slip Gaji');
    });

    $user = User::query()->findOrFail($mine->user_id);
    actingInOrg($user, $this->w->org)->get('/me/payslips/'.$slip->id)->assertOk();
    actingInOrg($user, $this->w->org)->get('/me/payslips/'.slipOf($other)->id)->assertNotFound();
    actingInOrg($user, $this->w->org)->get('/payroll/slips/'.slipOf($other)->id)->assertNotFound();

    runFor('2026-02-01', $this->w->po);
    actingInOrg($user, $this->w->org)->get('/me/payslips/'.slipOf($mine)->id)->assertNotFound();
});

// #42
it('passes the statutory fixtures against the test sets and the seeded LHDN table', function () {
    $fixtures = app(StatutoryFixtures::class);
    foreach (['T-EPF', 'T-SOCSO', 'T-EIS', 'T-MTD'] as $code) {
        expect($fixtures->run(StatutoryRateSet::query()->where('code', $code)->firstOrFail()))->toBe([], $code);
    }
    StatutoryRateSet::query()->delete();
    (new StatutoryRatesSeeder)->run();
    expect($fixtures->run(StatutoryRateSet::query()->where('code', 'MTD-YA2026')->firstOrFail()))->toBe([]);
    // Seeded KWSP fixture rows are right; only the not-yet-imported bands are reported.
    $epf = $fixtures->run(StatutoryRateSet::query()->where('code', 'EPF-2025-10')->firstOrFail());
    expect(collect($epf)->filter(fn ($f) => str_contains($f, '→'))->all())->toBe([])->and($epf)->not->toBeEmpty();
});

it('imports a CSV into a draft, publishes after fixtures and needs a second person to verify', function () {
    $sa = superAdmin();
    $this->actingAs($sa);
    $csv = "wage_from,wage_to,employer_amount,employee_amount\n0.00,100.00,0.20,0.20\n";
    $result = app(RateCsvImporter::class)->import(RateScheme::Eis, $csv, 'EIS-NEW', '2027-01-01');
    expect($result['rows'])->toBe(1)->and($result['set']->status->value)->toBe('draft');
    expect(fn () => app(RateCsvImporter::class)->import(RateScheme::Eis, "wage_from,wage_to,employer_amount,employee_amount\n0,1,<PDF>,1\n", 'EIS-NEW'))
        ->toThrow(StatutoryRateException::class);

    $set = StatutoryRateSet::query()->where('code', 'EIS-NEW')->firstOrFail();
    StatutoryRatesSeeder::eisParams($set->id);
    expect(app(RateSetActions::class)->publish($set))->not->toBe([])->and($set->refresh()->status->value)->toBe('draft');

    $copy = app(RateSetActions::class)->copy(StatutoryRateSet::query()->where('code', 'T-EIS')->firstOrFail(), 'EIS-2027', CarbonImmutable::parse('2027-01-01'));
    expect(app(RateSetActions::class)->publish($copy))->toBe([])->and($copy->refresh()->status->value)->toBe('unverified')
        ->and(StatutoryRateSet::query()->where('code', 'T-EIS')->firstOrFail()->effective_to?->toDateString())->toBe('2026-12-31');

    expect(fn () => app(RateSetActions::class)->verify($copy, $sa))->toThrow(StatutoryRateException::class);
    $other = superAdmin();
    $copy->forceFill(['is_placeholder' => false])->save();
    expect(app(RateSetActions::class)->verify($copy, $other)->status->value)->toBe('verified');
});
