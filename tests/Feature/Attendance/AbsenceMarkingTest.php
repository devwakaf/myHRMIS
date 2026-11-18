<?php

// spec-03 §11 cases 17–21 (absent marking) and edge 8 (holiday added after an auto absent).

use App\Domain\Attendance\Actions\AttendanceRequestActions;
use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Core\Events\HolidayListChanged;
use App\Domain\Core\Models\Holiday;
use App\Domain\Shared\Approval\ApprovalService;
use App\Models\User;
use Carbon\CarbonImmutable;

function absentDates(int $employeeId): array
{
    return Attendance::query()->live()->where('employee_id', $employeeId)->where('attendance_status', 'absent')
        ->orderBy('attendance_date')->pluck('attendance_date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();
}

beforeEach(function () {
    $this->w = attendanceWorld();
    $this->e1 = worker();
    weeklyRoster($this->e1, $this->w->pagi);
});

it('waits for window end + buffer before marking absent (case 17)', function () {
    markAbsencesAt('2026-11-04 01:30:00');
    expect(absentDates($this->e1->id))->toBe(['2026-11-02']);

    markAbsencesAt('2026-11-05 01:30:00');
    expect(absentDates($this->e1->id))->toContain('2026-11-03');
});

it('never marks absent on public holidays or rest days (case 18)', function () {
    markAbsencesAt('2026-12-29 03:00:00');
    $dates = absentDates($this->e1->id);

    expect($dates)->not->toContain('2026-12-25')
        ->and($dates)->not->toContain('2026-12-26')
        ->and($dates)->not->toContain('2026-12-27')
        ->and($dates)->toContain('2026-12-24');
});

it('clips to the joining date and skips employees without check-in (case 19, edge 9)', function () {
    $late = worker(null, ['date_of_joining' => '2026-11-10']);
    weeklyRoster($late, $this->w->pagi);
    $free = worker();
    weeklyRoster($free, $this->w->pagi);
    EmployeeAttendanceProfile::query()->create(['employee_id' => $free->id, 'checkin_required' => false]);

    markAbsencesAt('2026-11-20 03:00:00');
    expect(collect(absentDates($late->id))->min())->toBe('2026-11-10')
        ->and(absentDates($free->id))->toBe([])
        ->and(app(AttendanceForPayroll::class)->periodSummary($free, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))->unmarkedDays)->toBe(0);
});

it('skips dates covered by a pending request; marks them once it is rejected (case 20)', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'attendance_request');
    $actions = app(AttendanceRequestActions::class);
    $request = $actions->saveDraft(null, $this->e1, ['kind' => 'wfh', 'from_date' => '2026-11-04', 'to_date' => '2026-11-04', 'explanation' => 'Kerja dari rumah']);
    $actions->submit($request, User::query()->findOrFail($this->e1->user_id));

    markAbsencesAt('2026-11-06 01:30:00');
    expect(absentDates($this->e1->id))->not->toContain('2026-11-04');

    app(ApprovalService::class)->reject($request->approvalRequest()->firstOrFail(), $this->w->approver, 'Tidak diluluskan');
    markAbsencesAt('2026-11-06 02:00:00');
    expect(absentDates($this->e1->id))->toContain('2026-11-04');
});

it('is idempotent (case 21)', function () {
    markAbsencesAt('2026-11-10 01:30:00');
    $first = Attendance::query()->count();
    markAbsencesAt('2026-11-10 01:45:00');

    expect(Attendance::query()->count())->toBe($first)->and($first)->toBeGreaterThan(0);
});

it('cancels an auto absent when its date becomes a holiday (edge 8)', function () {
    markAbsencesAt('2026-11-05 01:30:00');
    expect(absentDates($this->e1->id))->toContain('2026-11-03');

    Holiday::query()->create(['holiday_list_id' => $this->w->list->id, 'date' => '2026-11-03', 'name' => 'Cuti Peristiwa', 'kind' => 'ad_hoc', 'is_half_day' => false, 'is_mandatory' => true]);
    HolidayListChanged::dispatch($this->w->list->id, ['2026-11-03'], []);

    expect(absentDates($this->e1->id))->not->toContain('2026-11-03');
});
