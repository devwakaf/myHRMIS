<?php

// spec-03 §11 cases 1–5 (shift resolution) and edge cases 1–3.

use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Services\ShiftAssignmentWriter;
use App\Domain\Attendance\Services\ShiftCalendar;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->w = attendanceWorld();
    $this->calendar = app(ShiftCalendar::class);
});

it('resolves punches inside the half-open PAGI window only (case 1)', function () {
    $e1 = worker();
    weeklyRoster($e1, $this->w->pagi);
    $at = fn (string $t) => $this->calendar->occurrenceAt($e1, CarbonImmutable::parse($t));

    expect($at('2026-11-02 06:59:00'))->toBeNull()
        ->and($at('2026-11-02 07:00:00')?->shift->code)->toBe('PAGI')
        ->and($at('2026-11-02 07:00:00')?->date->toDateString())->toBe('2026-11-02')
        ->and($at('2026-11-02 18:59:59')?->shift->code)->toBe('PAGI')
        ->and($at('2026-11-02 19:00:00'))->toBeNull();
});

it('attributes an after-midnight punch to the overnight shift start date (case 2, edge 1)', function () {
    $e = worker();
    app(ShiftAssignmentWriter::class)->add($e, $this->w->malam, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));

    $occ = $this->calendar->occurrenceAt($e, CarbonImmutable::parse('2026-11-03 05:50:00'));
    expect($occ?->shift->code)->toBe('MALAM')
        ->and($occ?->date->toDateString())->toBe('2026-11-02')
        ->and($occ?->end->toDateTimeString())->toBe('2026-11-03 06:00:00');
});

it('cuts neighbouring windows at the midpoint when multiple assignments are allowed (case 3, edge 2)', function () {
    AttendanceSettings::current()->forceFill(['allow_multiple_assignments' => true])->save();
    $a = shiftType('A', '08:00', '12:00', ['break_minutes' => 0]);
    $b = shiftType('B', '14:00', '18:00', ['break_minutes' => 0]);
    $e = worker();
    $writer = app(ShiftAssignmentWriter::class);
    $writer->add($e, $a, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));
    $writer->add($e, $b, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));

    expect($this->calendar->occurrenceAt($e, CarbonImmutable::parse('2026-11-02 12:59:00'))?->shift->code)->toBe('A')
        ->and($this->calendar->occurrenceAt($e, CarbonImmutable::parse('2026-11-02 13:00:00'))?->shift->code)->toBe('B');
});

it('flags nominally overlapping shifts as ambiguous and sends them to review (case 4, edge 3)', function () {
    AttendanceSettings::current()->forceFill(['allow_multiple_assignments' => true])->save();
    $a = shiftType('A', '08:00', '14:00', ['break_minutes' => 0]);
    $b = shiftType('B', '13:00', '18:00', ['break_minutes' => 0]);
    $e = worker();
    // Nominal overlap is refused by the writer, so the second row is inserted directly to simulate legacy / import data.
    app(ShiftAssignmentWriter::class)->add($e, $a, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));
    ShiftAssignment::query()->create([
        'employee_id' => $e->id, 'shift_type_id' => $b->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'status' => 'submitted', 'source' => 'manual',
    ]);

    $occ = $this->calendar->occurrenceAt($e, CarbonImmutable::parse('2026-11-02 13:30:00'));
    expect($occ?->ambiguous)->toBeTrue();

    $punch = punchAt($e, '2026-11-02 13:30:00');
    processAt('2026-11-02 13:31:00');
    expect($punch->refresh()->processing_state->value)->toBe('needs_review')
        ->and($punch->review_reason?->value)->toBe('ambiguous_shift');
});

it('falls back to the default shift after the assignment ends (case 5)', function () {
    $pejabat = shiftType('PEJABAT2', '08:30', '17:30', ['break_minutes' => 60]);
    $e = worker($pejabat);
    app(ShiftAssignmentWriter::class)->add($e, $this->w->pagi, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));

    expect($this->calendar->occurrenceAt($e, CarbonImmutable::parse('2026-11-30 08:00:00'))?->shift->code)->toBe('PAGI')
        ->and($this->calendar->occurrenceAt($e, CarbonImmutable::parse('2026-12-01 08:00:00'))?->shift->code)->toBe('PEJABAT2')
        ->and($this->calendar->occurrenceAt($e, CarbonImmutable::parse('2026-12-01 08:00:00'))?->source())->toBe('default');
});
