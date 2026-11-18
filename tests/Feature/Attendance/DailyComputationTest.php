<?php

// spec-03 §11 cases 6–16 (daily computation, processor, review actions).

use App\Domain\Attendance\Actions\AttendanceRecordActions;
use App\Domain\Attendance\Actions\MarkAttendanceManually;
use App\Domain\Attendance\Enums\CheckinSource;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Services\ShiftAssignmentWriter;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Leave\Support\LeaveDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

function liveRow(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->live()->where('employee_id', $e->id)->whereDate('attendance_date', $date)->first();
}

beforeEach(function () {
    $this->w = attendanceWorld();
    $this->e1 = worker();
    weeklyRoster($this->e1, $this->w->pagi);
});

it('computes worked minutes with break overlap and no lateness inside grace (case 6)', function () {
    punchAt($this->e1, '2026-11-02 08:09:00');
    punchAt($this->e1, '2026-11-02 17:05:00');
    processAt('2026-11-02 19:00:00');

    $row = liveRow($this->e1, '2026-11-02');
    expect($row->attendance_status->value)->toBe('present')
        ->and($row->worked_minutes)->toBe(476)
        ->and((string) $row->worked_hours)->toBe('7.93')
        ->and($row->is_late)->toBeFalse()
        ->and($row->source->value)->toBe('auto')
        ->and($row->day_type->value)->toBe('normal');
});

it('marks full lateness beyond the grace period (case 7)', function () {
    punchAt($this->e1, '2026-11-02 08:11:00');
    punchAt($this->e1, '2026-11-02 17:00:00');
    processAt('2026-11-02 19:00:00');

    $row = liveRow($this->e1, '2026-11-02');
    expect($row->is_late)->toBeTrue()->and($row->late_minutes)->toBe(11);
});

it('turns a short day into half day (worked basis) with early exit (case 8)', function () {
    punchAt($this->e1, '2026-11-02 08:00:00');
    punchAt($this->e1, '2026-11-02 11:30:00');
    processAt('2026-11-02 19:00:00');

    $row = liveRow($this->e1, '2026-11-02');
    expect($row->worked_minutes)->toBe(210)
        ->and($row->attendance_status->value)->toBe('half_day')
        ->and($row->half_day_basis?->value)->toBe('worked')
        ->and($row->other_half_status?->value)->toBe('absent')
        ->and($row->is_early_exit)->toBeTrue()
        ->and($row->early_exit_minutes)->toBe(330);
});

it('marks absent below the absent threshold with punches linked (case 9)', function () {
    $a = punchAt($this->e1, '2026-11-02 08:00:00');
    $b = punchAt($this->e1, '2026-11-02 09:30:00');
    processAt('2026-11-02 19:00:00');

    $row = liveRow($this->e1, '2026-11-02');
    expect($row->attendance_status->value)->toBe('absent')
        ->and($a->refresh()->attendance_id)->toBe($row->id)
        ->and($b->refresh()->processing_state->value)->toBe('processed');
});

it('sends a single punch to review and absent marking skips that date (case 10)', function () {
    $p = punchAt($this->e1, '2026-11-02 08:02:00');
    processAt('2026-11-02 19:00:00');
    expect($p->refresh()->review_reason?->value)->toBe('single_punch');

    markAbsencesAt('2026-11-05 03:00:00');
    expect(liveRow($this->e1, '2026-11-02'))->toBeNull()
        ->and(liveRow($this->e1, '2026-11-03')?->attendance_status->value)->toBe('absent');
});

it('halves thresholds on a half-day weekly off (case 11, edge 15)', function () {
    $this->w = attendanceWorld('2026-11-02 07:00:00', [6]);
    $separuh = shiftType('SEP', '08:00', '13:00', ['break_minutes' => 0]);
    $e = worker();
    app(ShiftAssignmentWriter::class)->add($e, $separuh, CarbonImmutable::parse('2026-11-07'), CarbonImmutable::parse('2026-11-07'));
    punchAt($e, '2026-11-07 08:00:00');
    punchAt($e, '2026-11-07 09:40:00');
    processAt('2026-11-07 16:00:00');

    $row = liveRow($e, '2026-11-07');
    expect($row->is_half_day_off)->toBeTrue()
        ->and($row->day_type->value)->toBe('normal')
        ->and($row->normal_minutes)->toBe(150)
        ->and($row->worked_minutes)->toBe(100)
        ->and($row->attendance_status->value)->toBe('half_day');
});

it('treats every worked minute on a rest day as OT and deducts the break (case 12)', function () {
    $e = worker($this->w->pagi);
    punchAt($e, '2026-11-08 09:00:00');
    punchAt($e, '2026-11-08 16:00:00');
    processAt('2026-11-08 20:00:00');

    $row = liveRow($e, '2026-11-08');
    expect($row->attendance_status->value)->toBe('present')
        ->and($row->day_type->value)->toBe('rest_day')
        ->and($row->worked_minutes)->toBe(360)
        ->and($row->ot_detected_minutes)->toBe(360)
        ->and($row->is_late)->toBeFalse();
});

it('ignores punches of a cancelled auto row until HR reprocesses (case 13)', function () {
    punchAt($this->e1, '2026-11-02 08:00:00');
    punchAt($this->e1, '2026-11-02 17:00:00');
    processAt('2026-11-02 19:00:00');
    $row = liveRow($this->e1, '2026-11-02');

    app(AttendanceRecordActions::class)->cancel($row, $this->w->hr, 'Salah rekod');
    expect(EmployeeCheckin::query()->where('employee_id', $this->e1->id)->pluck('processing_state')->map->value->unique()->all())->toBe(['ignored']);

    processAt('2026-11-02 23:00:00');
    expect(liveRow($this->e1, '2026-11-02'))->toBeNull();

    $ids = EmployeeCheckin::query()->where('employee_id', $this->e1->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    app(AttendanceRecordActions::class)->reprocess($ids, $this->w->hr);
    expect(liveRow($this->e1, '2026-11-02')?->attendance_status->value)->toBe('present');
});

it('never overwrites a manual row automatically; "Gantikan" supersedes it (case 14)', function () {
    $manual = app(MarkAttendanceManually::class)->mark($this->e1, CarbonImmutable::parse('2026-11-02'), ['attendance_status' => 'absent', 'remarks' => 'Tiada maklumat'], $this->w->hr)['attendance'];
    $a = punchAt($this->e1, '2026-11-02 08:00:00');
    $b = punchAt($this->e1, '2026-11-02 17:00:00');
    processAt('2026-11-02 19:00:00');

    expect($a->refresh()->review_reason?->value)->toBe('existing_attendance')
        ->and($manual->refresh()->status->value)->toBe('submitted');

    app(AttendanceRecordActions::class)->replaceExisting([$a->id, $b->id], $this->w->hr);
    $row = liveRow($this->e1, '2026-11-02');
    expect($row->attendance_status->value)->toBe('present')
        ->and($row->supersedes_id)->toBe($manual->id)
        ->and($manual->refresh()->status->value)->toBe('superseded');
});

it('supersedes an auto absent when late import punches arrive (case 15, edge 6)', function () {
    markAbsencesAt('2026-11-05 01:30:00');
    $absent = liveRow($this->e1, '2026-11-03');
    expect($absent?->attendance_status->value)->toBe('absent');

    punchAt($this->e1, '2026-11-03 08:00:00', CheckinSource::Import);
    punchAt($this->e1, '2026-11-03 17:00:00', CheckinSource::Import);
    processAt('2026-11-05 02:00:00');

    $row = liveRow($this->e1, '2026-11-03');
    expect($row->attendance_status->value)->toBe('present')
        ->and($row->supersedes_id)->toBe($absent->id);
});

it('isolates a failing group: E1 goes to processing_error, E2 is processed (case 16)', function () {
    $e2 = worker();
    weeklyRoster($e2, $this->w->pagi);
    $failing = $this->e1->id;
    app()->instance(LeaveQuery::class, new class($failing) implements LeaveQuery
    {
        public function __construct(private int $failing) {}

        public function approvedLeaveOn(Employee $employee, CarbonInterface $date): ?LeaveDay
        {
            if ($employee->id === $this->failing) {
                throw new RuntimeException('simulated failure');
            }

            return null;
        }
    });

    $p = punchAt($this->e1, '2026-11-02 08:00:00');
    punchAt($this->e1, '2026-11-02 17:00:00');
    punchAt($e2, '2026-11-02 08:00:00');
    punchAt($e2, '2026-11-02 17:00:00');
    processAt('2026-11-02 19:00:00');

    expect($p->refresh()->review_reason?->value)->toBe('processing_error')
        ->and($p->review_note)->toContain('simulated failure')
        ->and(liveRow($e2, '2026-11-02')?->attendance_status->value)->toBe('present');
});
