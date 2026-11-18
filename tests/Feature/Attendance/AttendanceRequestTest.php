<?php

// spec-03 §11 cases 27–31f (Permohonan Kehadiran / Permohonan Shift through the approval engine).

use App\Domain\Attendance\Actions\AttendanceRequestActions;
use App\Domain\Attendance\Actions\ShiftRequestActions;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftRequest;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Events\ApprovalCompleted;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use App\Domain\Shared\Approval\Exceptions\RequestNotPending;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function fileRequest(Employee $e, array $data, bool $submit = true): AttendanceRequest
{
    $actions = app(AttendanceRequestActions::class);
    $request = $actions->saveDraft(null, $e, $data + ['kind' => 'wfh', 'explanation' => 'Urusan kerja']);
    if ($submit) {
        $actions->submit($request, User::query()->findOrFail($e->user_id));
    }

    return $request->refresh();
}

function approveRequest(AttendanceRequest|ShiftRequest $doc, User $approver): void
{
    app(ApprovalService::class)->approve($doc->approvalRequest()->firstOrFail(), $approver);
}

function liveOn(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->live()->where('employee_id', $e->id)->whereDate('attendance_date', $date)->first();
}

beforeEach(function () {
    $this->w = attendanceWorld('2026-11-01 09:00:00');
    $this->approver2 = userIn($this->w->org, 'kakitangan', ['name' => 'Pelulus 2']);
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'attendance_request');
    $this->e1 = worker();
    weeklyRoster($this->e1, $this->w->pagi);
});

it('creates WFH rows on working days and skips rest days (case 27)', function () {
    $r = fileRequest($this->e1, ['from_date' => '2026-11-02', 'to_date' => '2026-11-08']);
    approveRequest($r, $this->w->approver);

    $r->refresh()->load('days');
    expect($r->status->value)->toBe('approved')
        ->and($r->effects_applied_at)->not->toBeNull()
        ->and($r->days->where('outcome.value', 'created'))->toHaveCount(5)
        ->and($r->days->where('outcome.value', 'skipped_rest_or_holiday'))->toHaveCount(2)
        ->and(liveOn($this->e1, '2026-11-02')?->attendance_status->value)->toBe('wfh')
        ->and(liveOn($this->e1, '2026-11-07'))->toBeNull();
});

it('creates a half-day on-duty row completed by morning punches (case 28)', function () {
    $r = fileRequest($this->e1, ['kind' => 'on_duty', 'duty_location' => 'Pejabat pelanggan', 'from_date' => '2026-11-04', 'to_date' => '2026-11-04',
        'is_half_day' => true, 'half_day_date' => '2026-11-04', 'half_day_session' => 'pm']);
    approveRequest($r, $this->w->approver);

    $row = liveOn($this->e1, '2026-11-04');
    expect($row->attendance_status->value)->toBe('half_day')
        ->and($row->half_day_basis?->value)->toBe('request')
        ->and($row->other_half_status?->value)->toBe('pending');

    punchAt($this->e1, '2026-11-04 08:00:00');
    punchAt($this->e1, '2026-11-04 12:00:00');
    processAt('2026-11-04 19:00:00');
    expect($row->refresh()->other_half_status?->value)->toBe('present');
});

it('replaces an auto absent and restores it when the approved request is cancelled (case 29)', function () {
    markAbsencesAt('2026-11-05 01:30:00');
    $absent = liveOn($this->e1, '2026-11-03');
    expect($absent?->attendance_status->value)->toBe('absent');

    $r = fileRequest($this->e1, ['from_date' => '2026-11-03', 'to_date' => '2026-11-03']);
    approveRequest($r, $this->w->approver);
    expect($r->days()->first()->outcome->value)->toBe('replaced_absent')
        ->and(liveOn($this->e1, '2026-11-03')->attendance_status->value)->toBe('wfh');

    app(AttendanceRequestActions::class)->cancelApproved($r->refresh(), $this->w->hr, 'Silap tarikh', true);
    expect(liveOn($this->e1, '2026-11-03')->id)->toBe($absent->id)
        ->and(Attendance::query()->where('attendance_request_id', $r->id)->first()->status->value)->toBe('cancelled');
});

it('applies effects only at the final step of the seeded two-step route, inside the transaction (case 30)', function () {
    $hrExec = userIn($this->w->org, 'eksekutif_hr', ['name' => 'Eksekutif HR']);
    $boss = worker();
    $e = worker(null, ['reports_to_id' => $boss->id]);
    weeklyRoster($e, $this->w->pagi);
    // The route SeedAttendanceDefaults publishes (beforeEach replaced it with a one-step route).
    leaveWorkflow([
        ['name' => 'Penyelia', 'rule_type' => 'reports_to', 'rule_config' => ['level' => 1], 'mode' => 'any_one', 'skip_if_applicant' => true,
            'skip_if_same_as_previous' => false, 'exclude_submitter' => true, 'on_no_approver' => 'skip', 'condition' => null],
        ['name' => 'HR', 'rule_type' => 'role', 'rule_config' => ['role' => 'eksekutif_hr'], 'mode' => 'any_one', 'skip_if_applicant' => false,
            'skip_if_same_as_previous' => true, 'exclude_submitter' => true, 'on_no_approver' => 'fail', 'condition' => null],
    ], 'attendance_request');

    $r = fileRequest($e, ['from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
    approveRequest($r, User::query()->findOrFail($boss->user_id));
    expect(Attendance::query()->where('attendance_request_id', $r->id)->count())->toBe(0)
        ->and($r->refresh()->effects_applied_at)->toBeNull();

    approveRequest($r, $hrExec);
    expect(Attendance::query()->where('attendance_request_id', $r->id)->count())->toBe(2)
        ->and($r->refresh()->effects_applied_at)->not->toBeNull();

    $listeners = collect(Event::getRawListeners()[ApprovalCompleted::class] ?? [])->map(fn ($l) => is_string($l) ? $l : (is_array($l) ? (is_object($l[0]) ? get_class($l[0]) : $l[0]) : 'closure'));
    expect($listeners->filter(fn ($l) => str_contains((string) $l, 'Attendance'))->all())->toBe([]);
});

it('refuses a submit where every day is a rest day or holiday (case 31)', function () {
    $draft = fileRequest($this->e1, ['from_date' => '2026-11-07', 'to_date' => '2026-11-08'], false);

    expect(fn () => app(AttendanceRequestActions::class)->submit($draft, User::query()->findOrFail($this->e1->user_id)))
        ->toThrow(ValidationException::class);
});

it('rejects at step 2 without any rows; further decisions are refused (case 31a)', function () {
    leaveWorkflow([leaveStep('Satu', $this->w->approver), leaveStep('Dua', $this->approver2)], 'attendance_request');
    $r = fileRequest($this->e1, ['from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
    approveRequest($r, $this->w->approver);
    app(ApprovalService::class)->reject($r->approvalRequest()->firstOrFail(), $this->approver2, 'Tidak perlu');

    expect($r->refresh()->status->value)->toBe('rejected')
        ->and(Attendance::query()->where('attendance_request_id', $r->id)->count())->toBe(0)
        ->and(fn () => approveRequest($r, $this->approver2))->toThrow(RequestNotPending::class);
});

it('skips a day that became leave between steps, and vetoes when nothing is left (case 31b)', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)]);
    LeavePeriod::query()->whereDate('from_date', '2026-01-01')->first() ?? LeavePeriod::factory()->create();
    $type = leaveTypeMake();
    allocate($this->e1, $type, '14');
    leaveWorkflow([leaveStep('Satu', $this->w->approver), leaveStep('Dua', $this->approver2)], 'attendance_request');

    $r = fileRequest($this->e1, ['from_date' => '2026-11-02', 'to_date' => '2026-11-06']);
    approveRequest($r, $this->w->approver);
    approveLeave(applyLeave($this->e1, $type, '2026-11-04'), $this->w->approver);
    approveRequest($r, $this->approver2);
    expect($r->days()->where('work_date', '2026-11-04')->first()->outcome->value)->toBe('skipped_leave')
        ->and($r->days()->where('outcome', 'created')->count())->toBe(4);

    $r2 = fileRequest($this->e1, ['from_date' => '2026-11-09', 'to_date' => '2026-11-10']);
    approveRequest($r2, $this->w->approver);
    approveLeave(applyLeave($this->e1, $type, '2026-11-09', '2026-11-10'), $this->w->approver);
    expect(fn () => approveRequest($r2, $this->approver2))->toThrow(ApprovalCompletionBlocked::class)
        ->and($r2->refresh()->status->value)->toBe('pending');
});

it('vetoes a shift request whose range became locked; nothing is written (case 31c)', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'shift_request');
    $actions = app(ShiftRequestActions::class);
    $sr = $actions->saveDraft(null, $this->e1, ['shift_type_id' => $this->w->malam->id, 'from_date' => '2026-11-11', 'to_date' => '2026-11-12']);
    $actions->submit($sr, User::query()->findOrFail($this->e1->user_id));

    app()->instance(PayrollLockQuery::class, new class implements PayrollLockQuery
    {
        public function isLocked(Employee|int $employee, CarbonImmutable $date): bool
        {
            return true;
        }

        public function lockedDatesIn(Employee|int $employee, DateRange $range): array
        {
            return iterator_to_array($range->dates(), false);
        }
    });

    expect(fn () => approveRequest($sr, $this->w->approver))->toThrow(ApprovalCompletionBlocked::class)
        ->and(ShiftAssignment::query()->where('shift_request_id', $sr->id)->count())->toBe(0);
});

it('rolls back the decision when onApprovalCompleted throws (case 31d)', function () {
    $r = fileRequest($this->e1, ['from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
    $calls = 0;
    Attendance::creating(function () use (&$calls) {
        if (++$calls > 1) {
            throw new RuntimeException('simulated');
        }
    });

    expect(fn () => approveRequest($r, $this->w->approver))->toThrow(RuntimeException::class);
    Attendance::flushEventListeners();

    expect($r->refresh()->status->value)->toBe('pending')
        ->and(Attendance::query()->where('attendance_request_id', $r->id)->count())->toBe(0)
        ->and($r->effects_applied_at)->toBeNull();
})->after(fn () => Attendance::flushEventListeners());

it('changes nothing when a pending request is withdrawn (case 31e)', function () {
    $r = fileRequest($this->e1, ['from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
    app(AttendanceRequestActions::class)->withdraw($r, User::query()->findOrFail($this->e1->user_id), 'Tidak jadi');

    expect($r->refresh()->status->value)->toBe('cancelled')
        ->and(Attendance::query()->where('employee_id', $this->e1->id)->count())->toBe(0);
});

it('skips the Penyelia step for an employee without reports_to (case 31f)', function () {
    userIn($this->w->org, 'eksekutif_hr');
    leaveWorkflow([
        ['name' => 'Penyelia', 'rule_type' => 'reports_to', 'rule_config' => ['level' => 1], 'mode' => 'any_one', 'skip_if_applicant' => true,
            'skip_if_same_as_previous' => false, 'exclude_submitter' => true, 'on_no_approver' => 'skip', 'condition' => null],
        ['name' => 'HR', 'rule_type' => 'role', 'rule_config' => ['role' => 'eksekutif_hr'], 'mode' => 'any_one', 'skip_if_applicant' => false,
            'skip_if_same_as_previous' => true, 'exclude_submitter' => true, 'on_no_approver' => 'fail', 'condition' => null],
    ], 'attendance_request');
    $draft = fileRequest($this->e1, ['from_date' => '2026-11-02', 'to_date' => '2026-11-02'], false);

    $route = app(ApprovalService::class)->preview($draft);
    expect($route->steps[0]['outcome'])->toBe('skipped')
        ->and($route->steps[0]['skip_reason'])->toBe('no_approver')
        ->and($route->steps[1]['outcome'])->toBe('pending');
});
