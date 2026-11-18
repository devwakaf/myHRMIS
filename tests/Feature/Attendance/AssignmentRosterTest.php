<?php

// spec-03 §11 cases 42–48 (assignments, rosters, shift-request split / restore, separation).

use App\Domain\Attendance\Actions\ShiftRequestActions;
use App\Domain\Attendance\Exceptions\AssignmentOverlap;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeRestDay;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftScheduleAssignment;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\RosterGenerator;
use App\Domain\Attendance\Services\ShiftAssignmentWriter;
use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalService;
use App\Models\User;
use Carbon\CarbonImmutable;

/** @return list<string> "start..end:CODE" for live assignments */
function liveAssignments(Employee $e): array
{
    return ShiftAssignment::query()->live()->where('employee_id', $e->id)->with('shiftType')->orderBy('start_date')->get()
        ->map(fn (ShiftAssignment $a) => $a->start_date->toDateString().'..'.($a->end_date?->toDateString() ?? '∞').':'.$a->shiftType->code)->all();
}

function cycleSchedule(string $code, array $slots): ShiftSchedule
{
    $s = ShiftSchedule::query()->create(['code' => $code, 'name' => $code, 'cycle_length_days' => count($slots)]);
    foreach ($slots as $i => $slot) {
        $s->days()->create(['day_index' => $i] + ($slot === 'rest' || $slot === 'off' ? ['kind' => $slot, 'shift_type_id' => null] : ['kind' => 'shift', 'shift_type_id' => $slot->id]));
    }

    return $s;
}

beforeEach(function () {
    $this->w = attendanceWorld('2026-11-01 09:00:00');
    $this->writer = app(ShiftAssignmentWriter::class);
});

it('treats an open end date as +∞ in the overlap check (case 42)', function () {
    $e = worker();
    $this->writer->add($e, $this->w->pagi, CarbonImmutable::parse('2026-11-01'), null);

    expect(fn () => $this->writer->add($e, $this->w->malam, CarbonImmutable::parse('2027-06-01'), null))->toThrow(AssignmentOverlap::class);
});

it('refuses to cancel an assignment with processed attendance; "Tamatkan" is allowed (case 43)', function () {
    $e = worker();
    $a = $this->writer->add($e, $this->w->pagi, CarbonImmutable::parse('2026-11-01'), null);
    punchAt($e, '2026-11-02 08:00:00');
    punchAt($e, '2026-11-02 17:00:00');
    processAt('2026-11-02 19:00:00');

    expect(fn () => $this->writer->cancel($a, $this->w->hr, 'x'))->toThrow(AttendanceRuleViolation::class)
        ->and(fn () => $this->writer->end($a, CarbonImmutable::parse('2026-11-01')))->toThrow(AttendanceRuleViolation::class);
    $this->writer->end($a->refresh(), CarbonImmutable::parse('2026-11-02'));
    expect($a->refresh()->end_date?->toDateString())->toBe('2026-11-02');
});

it('generates a 4-on-4-off cycle idempotently (case 44)', function () {
    $e = worker();
    $s = cycleSchedule('C4K4R', [$this->w->pagi, $this->w->pagi, $this->w->pagi, $this->w->pagi, 'rest', 'rest', 'rest', 'rest']);
    $roster = app(RosterGenerator::class)->assign(['employee_id' => $e->id, 'shift_schedule_id' => $s->id, 'anchor_date' => '2026-11-02', 'end_date' => '2026-11-13']);

    expect(liveAssignments($e))->toBe(['2026-11-02..2026-11-05:PAGI', '2026-11-10..2026-11-13:PAGI'])
        ->and(EmployeeRestDay::query()->where('employee_id', $e->id)->orderBy('rest_date')->pluck('rest_date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all())
        ->toBe(['2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09']);

    $roster->forceFill(['generated_through' => null])->save();
    app(RosterGenerator::class)->extend($roster->refresh());
    expect(liveAssignments($e))->toHaveCount(2)
        ->and(EmployeeRestDay::query()->where('employee_id', $e->id)->count())->toBe(4);
});

it('collapses a weekly pattern into one 5-day and one 1-day assignment per week (case 45)', function () {
    $sep = shiftType('SEP2', '08:00', '13:00', ['break_minutes' => 0]);
    $e = worker();
    weeklyRoster($e, $this->w->pagi, $sep, '2026-11-02', '2026-11-15');

    expect(liveAssignments($e))->toBe([
        '2026-11-02..2026-11-06:PAGI', '2026-11-07..2026-11-07:SEP2',
        '2026-11-09..2026-11-13:PAGI', '2026-11-14..2026-11-14:SEP2',
    ]);
});

it('splits a roster for an approved shift request and restores it exactly on cancel (case 46)', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'shift_request');
    $e = worker();
    $s = cycleSchedule('C4K4R', [$this->w->pagi, $this->w->pagi, $this->w->pagi, $this->w->pagi, 'rest', 'rest', 'rest', 'rest']);
    app(RosterGenerator::class)->assign(['employee_id' => $e->id, 'shift_schedule_id' => $s->id, 'anchor_date' => '2026-11-02', 'end_date' => '2026-11-13']);

    $actions = app(ShiftRequestActions::class);
    $sr = $actions->saveDraft(null, $e, ['shift_type_id' => $this->w->malam->id, 'from_date' => '2026-11-11', 'to_date' => '2026-11-12', 'reason' => 'Tukar']);
    $actions->submit($sr, User::query()->findOrFail($e->user_id));
    app(ApprovalService::class)->approve($sr->approvalRequest()->firstOrFail(), $this->w->approver);

    expect(liveAssignments($e))->toBe(['2026-11-02..2026-11-05:PAGI', '2026-11-10..2026-11-10:PAGI', '2026-11-11..2026-11-12:MALAM', '2026-11-13..2026-11-13:PAGI']);

    $actions->cancelApproved($sr->refresh(), $this->w->hr, 'Tidak jadi', true);
    expect(liveAssignments($e))->toBe(['2026-11-02..2026-11-05:PAGI', '2026-11-10..2026-11-13:PAGI']);
});

it('trims assignments and ends rosters on separation (case 47)', function () {
    $e = worker();
    weeklyRoster($e, $this->w->pagi, 'rest', '2026-11-02', null);
    $e->forceFill(['relieving_date' => '2026-11-15'])->save();
    EmployeeSeparated::dispatch($e);

    $rows = ShiftAssignment::query()->live()->where('employee_id', $e->id)->get();
    expect(liveAssignments($e))->toBe(['2026-11-02..2026-11-06:PAGI', '2026-11-09..2026-11-13:PAGI'])
        ->and($rows->every(fn ($a) => $a->start_date->lessThanOrEqualTo(CarbonImmutable::parse('2026-11-15'))))->toBeTrue()
        ->and(ShiftScheduleAssignment::query()->where('employee_id', $e->id)->first()->end_date?->toDateString())->toBe('2026-11-15')
        ->and(EmployeeRestDay::query()->where('employee_id', $e->id)->whereDate('rest_date', '>', '2026-11-15')->count())->toBe(0);
});

it('lets the roster decide rest days over the weekly-off pattern (case 48)', function () {
    $e = worker();
    $s = cycleSchedule('ROSTER', [$this->w->pagi, $this->w->pagi, 'rest', $this->w->pagi, $this->w->pagi, 'off', $this->w->pagi]);
    app(RosterGenerator::class)->assign(['employee_id' => $e->id, 'shift_schedule_id' => $s->id, 'anchor_date' => '2026-11-02', 'end_date' => '2026-11-30']);
    $resolver = app(DayTypeResolver::class);

    expect($resolver->for($e, CarbonImmutable::parse('2026-11-04'))->dayType->value)->toBe('rest_day') // Wednesday
        ->and($resolver->for($e, CarbonImmutable::parse('2026-11-08'))->dayType->value)->toBe('normal') // Sunday, rostered shift
        ->and($resolver->for($e, CarbonImmutable::parse('2026-12-25'))->dayType->value)->toBe('public_holiday');

    punchAt($e, '2026-11-08 08:00:00');
    punchAt($e, '2026-11-08 17:00:00');
    processAt('2026-11-08 19:00:00');
    expect(Attendance::query()->where('employee_id', $e->id)->first()->day_type->value)->toBe('normal');
});
