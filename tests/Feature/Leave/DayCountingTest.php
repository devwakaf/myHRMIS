<?php

// spec-02 §7 cases 1–7 (day counting, §3.1).

use App\Domain\Core\Actions\GenerateWeeklyOffs;
use App\Domain\Core\Actions\HolidayAssignmentActions;
use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Core\Services\WorkHistoryRecorder;
use App\Domain\Leave\Enums\DayKind;
use App\Domain\Leave\Enums\DaySession;
use App\Domain\Leave\Services\LeaveDayCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->w = leaveWorld('2026-06-01', ['2026-06-10' => 'Hari Organisasi']);
    $this->calc = app(LeaveDayCalculator::class);
    $this->employee = staff();
});

// #1
it('counts working days, rest days, a holiday and a PM half day', function () {
    $type = leaveTypeMake();
    $b = $this->calc->build($this->employee, $type, '2026-06-08', '2026-06-16', '2026-06-16', DaySession::Pm);

    expect($b->calendarDays)->toBe(9)
        ->and($b->countsByKind())->toMatchArray(['working' => 6, 'weekly_off' => 2, 'holiday' => 1])
        ->and($b->totalDays)->toBe('5.500')
        ->and($b->days[8]['session'])->toBe(DaySession::Pm);
});

// #2
it('deducts rest days and holidays for a counts_holidays type', function () {
    $type = leaveTypeMake(['counts_holidays' => true]);

    expect($this->calc->build($this->employee, $type, '2026-06-08', '2026-06-16', '2026-06-16', DaySession::Pm)->totalDays)->toBe('8.500');
});

// #3
it('treats a half-day Saturday as half working and refuses a half-day flag on it', function () {
    $w = leaveWorld('2026-06-01', [], [6]);
    $employee = staff();
    $type = leaveTypeMake();
    $b = $this->calc->build($employee, $type, '2026-06-05', '2026-06-06');

    expect($b->totalDays)->toBe('1.500')
        ->and($b->days[1]['day_kind'])->toBe(DayKind::HalfWorking)
        ->and(fn () => $this->calc->build($employee, $type, '2026-06-05', '2026-06-06', '2026-06-06', DaySession::Am))
        ->toThrow(ValidationException::class);
});

// #4
it('rejects a half day in the middle of a range or on a full holiday', function () {
    $type = leaveTypeMake();

    expect(fn () => $this->calc->build($this->employee, $type, '2026-06-08', '2026-06-12', '2026-06-09', DaySession::Am))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->calc->build($this->employee, $type, '2026-06-10', '2026-06-12', '2026-06-10', DaySession::Am))
        ->toThrow(ValidationException::class);
});

// #5
it('refuses a range made only of rest days', function () {
    try {
        $this->calc->build($this->employee, leaveTypeMake(), '2026-06-06', '2026-06-07');
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['from_date'][0])->toBe(__('leave.errors.no_working_days'));
    }
});

// #6
it('suggests the Bersalin end date from the per-event maximum (98 days)', function () {
    expect($this->calc->suggestedToDate(seededType('BSL'), '2026-06-01')?->toDateString())->toBe('2026-09-06');
});

// #7
it('resolves days per holiday-list segment when the employee moves branch mid-range', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $employee = staff(['branch_id' => $branchA->id]);
    app(WorkHistoryRecorder::class)->correctCurrent($employee);

    $listA = HolidayList::factory()->create();
    $listB = HolidayList::factory()->create();
    app(GenerateWeeklyOffs::class)->handle($listA);
    app(GenerateWeeklyOffs::class)->handle($listB);
    Holiday::query()->create(['holiday_list_id' => $listB->id, 'date' => '2026-06-17', 'name' => 'Cuti B', 'kind' => 'state', 'is_half_day' => false, 'is_mandatory' => false]);
    foreach ([[$listA, $branchA], [$listB, $branchB]] as [$list, $branch]) {
        $hla = HolidayListAssignment::factory()->create(['holiday_list_id' => $list->id, 'assignable_type' => 'branch', 'assignable_id' => $branch->id, 'effective_from' => '2026-01-01']);
        app(HolidayAssignmentActions::class)->submit($hla, $this->w->hr);
    }

    $employee->update(['branch_id' => $branchB->id]);
    app(WorkHistoryRecorder::class)->transition($employee, CarbonImmutable::parse('2026-06-16'), WorkHistorySource::Movement);
    app(HolidayResolver::class)->flush();

    $b = app(LeaveDayCalculator::class)->build($employee, leaveTypeMake(), '2026-06-15', '2026-06-18');

    expect(array_map(fn ($d) => $d['day_kind'], $b->days))->toBe([DayKind::Working, DayKind::Working, DayKind::Holiday, DayKind::Working])
        ->and($b->days[0]['holiday_list_id'])->toBe($listA->id)
        ->and($b->days[2]['holiday_list_id'])->toBe($listB->id)
        ->and($b->totalDays)->toBe('3.000');
});
