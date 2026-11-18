<?php

// spec-03 §11 cases 49–58, 55a, 55b and 31g (OT per Akta Kerja, claims through the approval engine).
// W = RM2,600.00 → ORP 100.000000, H 8, HRP 12.500000 unless stated.

use App\Domain\Attendance\Actions\AttendanceConfigActions;
use App\Domain\Attendance\Actions\OvertimeClaimActions;
use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Services\AttendanceWriter;
use App\Domain\Attendance\Services\OvertimeCalculator;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function otRow(Employee $e, string $date, string $dayType, int $worked, ?int $ot = null, int $normal = 480, ?int $typeId = null): Attendance
{
    return app(AttendanceWriter::class)->create([
        'organisation_id' => $e->organisation_id, 'employee_id' => $e->id, 'attendance_date' => $date,
        'attendance_status' => 'present', 'day_type' => $dayType, 'worked_minutes' => $worked, 'normal_minutes' => $normal,
        'ot_detected_minutes' => $ot ?? ($dayType === 'normal' ? max(0, $worked - $normal) : $worked),
        'overtime_type_id' => $typeId ?? OvertimeType::query()->where('code', 'OT-AKTA')->value('id'), 'source' => AttendanceSource::Auto,
    ]);
}

function otAmount(Employee $e, Attendance $row, string $wage = '2600.00', ?OvertimeType $type = null): array
{
    $calc = app(OvertimeCalculator::class);
    $ctx = $calc->context($e, CarbonImmutable::parse('2026-11-01'), $wage);

    return $calc->line($row, $type ?? OvertimeType::query()->with('rules')->findOrFail($row->overtime_type_id), $ctx);
}

/** Many normal-day OT rows totalling $hours beyond normal in November. */
function otMonth(Employee $e, int $hours, int $perDay = 240): void
{
    $day = CarbonImmutable::parse('2026-11-02');
    $left = $hours * 60;
    while ($left > 0) {
        if ($day->isWeekday()) {
            $ot = min($perDay, $left);
            otRow($e, $day->toDateString(), 'normal', 480 + $ot);
            $left -= $ot;
        }
        $day = $day->addDay();
    }
}

function otDraft(Employee $e, string $wage = '2600.00'): OvertimeClaim
{
    $actions = app(OvertimeClaimActions::class);
    $claim = $actions->generate(CarbonImmutable::parse('2026-11-01'), $e->id)[0];

    return $actions->setManualWage($claim, $wage);
}

beforeEach(function () {
    $this->w = attendanceWorld('2026-12-01 09:00:00');
    $this->e = worker();
    $this->second = userIn($this->w->org, 'kakitangan', ['name' => 'Ketua']);
    leaveWorkflow([
        leaveStep('Ketua Jabatan', $this->w->approver),
        leaveStep('Pengurus HR', $this->second, ['condition' => [['attribute' => 'overtime_claim.needs_compliance_review', 'operator' => '=', 'value' => true]]]),
    ], 'overtime_claim');
});

it('pays normal-day OT at 1.5 × HRP (case 49)', function () {
    expect(otAmount($this->e, otRow($this->e, '2026-11-02', 'normal', 630))['amount'])->toBe('46.88');
});

it('pays rest-day work as half / full ORP plus 2.0 beyond normal (case 50)', function () {
    $amounts = [];
    foreach ([180 => '2026-11-07', 240 => '2026-11-08', 360 => '2026-11-14', 600 => '2026-11-15'] as $worked => $date) {
        $amounts[$worked] = otAmount($this->e, otRow($this->e, $date, 'rest_day', $worked))['amount'];
    }

    expect($amounts)->toBe([180 => '50.00', 240 => '50.00', 360 => '100.00', 600 => '150.00']);
});

it('pays public-holiday work as 2 ORP plus 3.0 beyond normal (case 51)', function () {
    expect(otAmount($this->e, otRow($this->e, '2026-11-02', 'public_holiday', 300))['amount'])->toBe('200.00')
        ->and(otAmount($this->e, otRow($this->e, '2026-11-03', 'public_holiday', 600))['amount'])->toBe('275.00');
});

it('keeps scale 6 and rounds only the line amount (case 52)', function () {
    expect(otAmount($this->e, otRow($this->e, '2026-11-02', 'normal', 600), '3000.00')['amount'])->toBe('43.27');
});

it('applies the RM4,000 threshold and the manual-labour override (case 53)', function () {
    $calc = app(OvertimeCalculator::class);
    $month = CarbonImmutable::parse('2026-11-01');
    expect($calc->context($this->e, $month, '4000.00')['eligibility']->value)->toBe('eligible_wage')
        ->and($calc->context($this->e, $month, '4000.01')['eligibility']->value)->toBe('not_eligible');

    $line = otAmount($this->e, otRow($this->e, '2026-11-02', 'normal', 600), '4000.01');
    expect($line['amount'])->toBe('0.00')->and($line['beyond_normal_minutes'])->toBe(120)->and($line['flags'])->toBe(['not_eligible']);

    EmployeeAttendanceProfile::query()->create(['employee_id' => $this->e->id, 'is_manual_labour' => true]);
    expect($calc->context($this->e, $month, '4000.01')['eligibility']->value)->toBe('eligible_manual_labour');
});

it('floors OT to the rounding unit (case 54)', function () {
    $type = OvertimeType::query()->with('rules')->where('code', 'OT-AKTA')->firstOrFail();
    $type->forceFill(['rounding_unit_minutes' => 30])->save();

    expect(otAmount($this->e, otRow($this->e, '2026-11-02', 'normal', 620), '2600.00', $type->refresh()->load('rules'))['beyond_normal_minutes'])->toBe(120);
});

it('flags the monthly cap, demands a reason and adds the compliance step without cutting pay (case 55, 31g)', function () {
    otMonth($this->e, 106, 330);
    $claim = otDraft($this->e);
    expect($claim->cap_exceeded)->toBeTrue()->and($claim->cap_counted_minutes)->toBe(106 * 60);
    $total = (string) $claim->total_amount;

    $actions = app(OvertimeClaimActions::class);
    expect(fn () => $actions->submit($claim, $this->w->hr))->toThrow(ValidationException::class);

    $actions->submit($claim->refresh(), $this->w->hr, 'Projek kecemasan');
    $request = $claim->refresh()->approvalRequest()->firstOrFail();
    expect($request->steps()->where('status', '!=', 'skipped')->count())->toBe(2)
        ->and((string) $claim->total_amount)->toBe($total)
        ->and(bccomp($total, '1987.50', 2))->toBeGreaterThanOrEqual(0); // 106 h × 12.50 × 1.5, never truncated by the cap

    // 31g: attributes only use catalogue keys; a 20 h claim skips the compliance step.
    $catalogue = array_keys(app(ApprovalTypeRegistry::class)->catalogue('overtime_claim'));
    expect(array_diff(array_keys($claim->approvalAttributes()), $catalogue))->toBe([]);

    $small = worker();
    otMonth($small, 20);
    $smallClaim = otDraft($small);
    $actions->submit($smallClaim, $this->w->hr);
    expect($smallClaim->refresh()->approvalRequest()->firstOrFail()->steps()->where('status', 'skipped')->count())->toBe(1);
});

it('exposes OT to payroll only after final approval; rejection frees lines; edits veto; posted claims stay (case 55a, 62)', function () {
    otMonth($this->e, 10);
    $claim = otDraft($this->e);
    $actions = app(OvertimeClaimActions::class);
    $payroll = app(AttendanceForPayroll::class);
    $actions->submit($claim, $this->w->hr);
    expect($payroll->overtimePayLines($this->e, CarbonImmutable::parse('2026-11-01')))->toBe([]);

    app(ApprovalService::class)->approve($claim->approvalRequest()->firstOrFail(), $this->w->approver);
    $lines = $payroll->overtimePayLines($this->e, CarbonImmutable::parse('2026-12-01'));
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->claimNumber)->toBe($claim->number)
        ->and($lines[0]->amount->toDecimalString())->toBe((string) $claim->refresh()->total_amount)
        ->and($lines[0]->minutes)->toBe(600)
        ->and($lines[0]->dayTypeBreakdown)->toBe(['normal' => 600]);

    $payroll->markOvertimePosted([$claim->id], 77);
    expect($payroll->overtimePayLines($this->e, CarbonImmutable::parse('2026-12-01')))->toBe([])
        ->and(fn () => $actions->cancel($claim->refresh(), $this->w->hr, 'x'))->toThrow(AttendanceRuleViolation::class);
    $payroll->releaseOvertime(77);
    expect($payroll->overtimePayLines($this->e, CarbonImmutable::parse('2026-12-01')))->toHaveCount(1);

    // Rejected claim frees its attendance for a new draft.
    $other = worker();
    otMonth($other, 5);
    $rejected = otDraft($other);
    $actions->submit($rejected, $this->w->hr);
    app(ApprovalService::class)->reject($rejected->approvalRequest()->firstOrFail(), $this->w->approver, 'Tidak sah');
    expect(otDraft($other)->lines()->count())->toBe($rejected->lines()->count());

    // An attendance row edited after submit vetoes completion.
    $third = worker();
    otMonth($third, 5);
    $pending = otDraft($third);
    $actions->submit($pending, $this->w->hr);
    $row = Attendance::query()->where('employee_id', $third->id)->first();
    app(AttendanceWriter::class)->update($row, ['worked_minutes' => 700, 'ot_detected_minutes' => 220]);
    expect(fn () => app(ApprovalService::class)->approve($pending->approvalRequest()->firstOrFail(), $this->w->approver))->toThrow(ApprovalCompletionBlocked::class);
});

it('hides amounts from a step approver without view-compensation (case 55b)', function () {
    otMonth($this->e, 4);
    $claim = otDraft($this->e);
    $context = $claim->decisionContext($this->w->approver);

    expect($context['total_hours'])->toBe('4.00')
        ->and($context['total_amount'])->toBeNull()
        ->and($context['lines'][0]['amount'])->toBeNull()
        ->and($claim->decisionContext($this->w->hr)['total_amount'])->toBe((string) $claim->total_amount);
});

it('pays max(contractual, statutory) to eligible employees (case 56)', function () {
    $save = fn (string $code, string $mult) => app(AttendanceConfigActions::class)->saveOvertimeType(null, ['code' => $code, 'name' => $code, 'basis' => 'contractual'], [
        ['day_type' => 'normal', 'beyond_normal_multiplier' => $mult],
        ['day_type' => 'rest_day', 'beyond_normal_multiplier' => '2.0', 'within_normal_mode' => 'orp_half_or_full'],
        ['day_type' => 'public_holiday', 'beyond_normal_multiplier' => '3.0', 'within_normal_mode' => 'orp_days', 'within_normal_orp_days' => '2'],
    ]);
    $low = $save('K125', '1.25');
    $high = $save('K200', '2.0');

    $a = otAmount($this->e, otRow($this->e, '2026-11-02', 'normal', 600, null, 480, $low->id), '2600.00', $low);
    $b = otAmount($this->e, otRow($this->e, '2026-11-03', 'normal', 600, null, 480, $high->id), '2600.00', $high);
    expect($a['amount'])->toBe('37.50')->and($a['multiplier'])->toBe('1.5000')
        ->and($b['amount'])->toBe('50.00');
});

it('rejects a statutory OT type without a public-holiday rule (case 57)', function () {
    expect(fn () => app(AttendanceConfigActions::class)->saveOvertimeType(null, ['code' => 'X', 'name' => 'X', 'basis' => 'statutory'], [
        ['day_type' => 'normal'], ['day_type' => 'rest_day'],
    ]))->toThrow(ValidationException::class);
});

it('keeps manual lines on regeneration and never duplicates claimed attendance (case 58)', function () {
    otMonth($this->e, 6);
    $claim = otDraft($this->e);
    $actions = app(OvertimeClaimActions::class);
    $actions->addManualLine($claim, ['work_date' => '2026-11-20', 'day_type' => 'normal', 'worked_minutes' => 60, 'amount' => '18.75', 'note' => 'Tambahan']);
    $count = $claim->lines()->count();

    $again = $actions->generate(CarbonImmutable::parse('2026-11-01'), $this->e->id)[0];
    expect($again->id)->toBe($claim->id)
        ->and($again->lines()->count())->toBe($count)
        ->and($again->lines()->where('is_manual', true)->count())->toBe(1);

    $actions->submit($again->refresh(), $this->w->hr);
    expect($actions->generate(CarbonImmutable::parse('2026-11-01'), $this->e->id))->toBe([]);
});
