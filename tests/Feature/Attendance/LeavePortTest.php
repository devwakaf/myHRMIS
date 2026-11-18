<?php

// spec-03 §11 cases 22–26c (Attendance's implementation of Leave's LeaveAttendancePort) and edge 7.

use App\Domain\Attendance\Adapters\LeaveAttendanceAdapter;
use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Exceptions\LeaveAttendanceConflict;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Notifications\LockedDatesSkipped;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

function rowsOn(Employee $e, string $date)
{
    return Attendance::query()->live()->where('employee_id', $e->id)->whereDate('attendance_date', $date)->get();
}

beforeEach(function () {
    $this->w = attendanceWorld('2026-11-02 07:00:00');
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)]);
    LeavePeriod::query()->whereDate('from_date', '2026-01-01')->first() ?? LeavePeriod::factory()->create();
    $this->type = leaveTypeMake();
    $this->e1 = worker();
    weeklyRoster($this->e1, $this->w->pagi);
    allocate($this->e1, $this->type, '14');
});

it('binds the real adapter over the Leave Null port (case 26c)', function () {
    expect(app(LeaveAttendancePort::class))->toBeInstanceOf(LeaveAttendanceAdapter::class);
});

it('marks a morning half-day leave and completes the other half from punches (case 22)', function () {
    approveLeave(applyLeave($this->e1, $this->type, '2026-11-04', null, ['half_day_date' => '2026-11-04', 'half_day_session' => 'am']), $this->w->approver);

    $row = rowsOn($this->e1, '2026-11-04')->first();
    expect($row->attendance_status->value)->toBe('half_day')
        ->and($row->half_day_basis?->value)->toBe('leave')
        ->and($row->half_day_session?->value)->toBe('am')
        ->and($row->other_half_status?->value)->toBe('pending');

    punchAt($this->e1, '2026-11-04 13:00:00');
    punchAt($this->e1, '2026-11-04 17:00:00');
    processAt('2026-11-04 19:00:00');
    expect($row->refresh()->other_half_status?->value)->toBe('present');
});

it('closes an unworked other half as absent and counts 0.5 absent days (case 23)', function () {
    approveLeave(applyLeave($this->e1, $this->type, '2026-11-04', null, ['half_day_date' => '2026-11-04', 'half_day_session' => 'am']), $this->w->approver);

    markAbsencesAt('2026-11-06 01:30:00');
    expect(rowsOn($this->e1, '2026-11-04')->first()->other_half_status?->value)->toBe('absent');

    $summary = app(AttendanceForPayroll::class)->periodSummary($this->e1, CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-04'));
    expect($summary->absentDays)->toBe('0.500')
        ->and($summary->absentDates)->toBe([['date' => '2026-11-04', 'fraction' => '0.500']]);
});

it('supersedes an auto absent and restores it on cancellation (case 24)', function () {
    markAbsencesAt('2026-11-05 01:30:00');
    $absent = rowsOn($this->e1, '2026-11-03')->first();
    expect($absent->attendance_status->value)->toBe('absent');

    $app = approveLeave(applyLeave($this->e1, $this->type, '2026-11-03'), $this->w->approver);
    expect(rowsOn($this->e1, '2026-11-03')->first()->attendance_status->value)->toBe('on_leave')
        ->and($absent->refresh()->status->value)->toBe('superseded');

    app(LeaveAttendancePort::class)->unmarkLeave($app);
    expect($absent->refresh()->status->value)->toBe('submitted')
        ->and(rowsOn($this->e1, '2026-11-03'))->toHaveCount(1);
});

it('refuses to overwrite a worked day: conflicts() reports it and markLeave throws (case 25)', function () {
    $app = applyLeave($this->e1, $this->type, '2026-11-04');
    punchAt($this->e1, '2026-11-04 08:00:00');
    punchAt($this->e1, '2026-11-04 17:00:00');
    processAt('2026-11-04 19:00:00');

    $port = app(LeaveAttendancePort::class);
    expect($port->conflicts($this->e1, new DateRange('2026-11-04', '2026-11-04')))
        ->toBe([['date' => '2026-11-04', 'session' => 'full', 'attendance_status' => 'present']]);

    // Leave's completion veto stops the approval before the port is called.
    expect(fn () => approveLeave($app, $this->w->approver))->toThrow(ApprovalCompletionBlocked::class);
    // Called anyway (race), the adapter throws and nothing changes.
    expect(fn () => $port->markLeave($app))->toThrow(LeaveAttendanceConflict::class)
        ->and(rowsOn($this->e1, '2026-11-04')->first()->attendance_status->value)->toBe('present');
});

it('skips a locked date with an HR notice and marks the rest (case 26)', function () {
    Notification::fake();
    app()->instance(PayrollLockQuery::class, new class implements PayrollLockQuery
    {
        public function isLocked(Employee|int $employee, CarbonImmutable $date): bool
        {
            return $date->toDateString() === '2026-11-04';
        }

        public function lockedDatesIn(Employee|int $employee, DateRange $range): array
        {
            return $range->contains('2026-11-04') ? [CarbonImmutable::parse('2026-11-04')] : [];
        }
    });

    approveLeave(applyLeave($this->e1, $this->type, '2026-11-04', '2026-11-05'), $this->w->approver);

    expect(rowsOn($this->e1, '2026-11-04'))->toHaveCount(0)
        ->and(rowsOn($this->e1, '2026-11-05')->first()->attendance_status->value)->toBe('on_leave');
    Notification::assertSentTo($this->w->hr, LockedDatesSkipped::class);
});

it('is idempotent and reports worked rest days (case 26a)', function () {
    $app = approveLeave(applyLeave($this->e1, $this->type, '2026-11-04'), $this->w->approver);
    app(LeaveAttendancePort::class)->markLeave($app);
    expect(rowsOn($this->e1, '2026-11-04'))->toHaveCount(1);

    $e = worker($this->w->pagi);
    punchAt($e, '2026-11-08 09:00:00');
    punchAt($e, '2026-11-08 15:00:00');
    processAt('2026-11-08 20:00:00');
    $worked = app(LeaveAttendancePort::class)->workedOn($e, CarbonImmutable::parse('2026-11-08'));
    expect($worked?->verified)->toBeTrue()
        ->and($worked?->halfDay)->toBeFalse()
        ->and($worked?->status)->toBe('present');
});

it('keeps a worked morning as the other half of an afternoon leave (case 26b)', function () {
    $app = applyLeave($this->e1, $this->type, '2026-11-04', null, ['half_day_date' => '2026-11-04', 'half_day_session' => 'pm']);
    punchAt($this->e1, '2026-11-04 08:00:00');
    punchAt($this->e1, '2026-11-04 12:00:00');
    processAt('2026-11-04 19:00:00');
    approveLeave($app, $this->w->approver);

    $row = rowsOn($this->e1, '2026-11-04')->first();
    expect($row->attendance_status->value)->toBe('half_day')
        ->and($row->half_day_basis?->value)->toBe('leave')
        ->and($row->half_day_session?->value)->toBe('pm')
        ->and($row->other_half_status?->value)->toBe('present');
});

it('reprocesses punches when approved leave is cancelled after the day (edge 7)', function () {
    $app = approveLeave(applyLeave($this->e1, $this->type, '2026-11-04'), $this->w->approver);
    $p = punchAt($this->e1, '2026-11-04 08:00:00');
    punchAt($this->e1, '2026-11-04 17:00:00');
    processAt('2026-11-04 19:00:00');
    expect($p->refresh()->review_reason?->value)->toBe('worked_on_leave');

    app(ApprovalService::class)->cancel($app->approvalRequest()->firstOrFail(), $this->w->hr, 'Batal cuti');
    processAt('2026-11-04 20:00:00');
    expect(rowsOn($this->e1, '2026-11-04')->first()->attendance_status->value)->toBe('present');
});
