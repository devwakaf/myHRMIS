<?php

use App\Domain\Core\Actions\GenerateWeeklyOffs;
use App\Domain\Core\Actions\HolidayAssignmentActions;
use App\Domain\Core\Actions\ImportPublicHolidays;
use App\Domain\Core\Actions\SaveHoliday;
use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Events\HolidayListChanged;
use App\Domain\Core\Exceptions\NoHolidayListAssigned;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\PublicHoliday;
use App\Domain\Core\Services\HolidayListAnalyzer;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Core\Services\WorkHistoryRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->org = inOrg(makeOrganisation());
    $this->hr = userIn($this->org, 'pengurus_hr');
    $this->assign = app(HolidayAssignmentActions::class);
});

function assignList(HolidayList $list, string $type, int $id, string $from): void
{
    $a = app(HolidayAssignmentActions::class);
    $a->submit($a->saveDraft(null, ['holiday_list_id' => $list->id, 'assignable_type' => $type, 'assignable_id' => $id, 'effective_from' => $from]), test()->hr);
}

// B8 #34
it('generates 104 Saturday/Sunday rows for 2026 and is idempotent', function () {
    $list = HolidayList::factory()->create(['weekly_off_days' => [6, 7]]);
    expect(app(GenerateWeeklyOffs::class)->handle($list))->toBe(104)
        ->and(app(GenerateWeeklyOffs::class)->handle($list))->toBe(0)
        ->and($list->holidays()->count())->toBe(104);
});

// B8 #35
it('counts half-day Saturdays as 0.5 in totals and working days', function () {
    $list = HolidayList::factory()->create(['weekly_off_days' => [7], 'half_day_weekly_off_days' => [6]]);
    app(GenerateWeeklyOffs::class)->handle($list->fresh());
    $employee = employee();
    assignList($list, 'organisation', $this->org->id, '2026-01-01');

    $totals = app(HolidayListAnalyzer::class)->totals($list->fresh());
    expect($totals['weekly_off'])->toBe('78.0'); // 52 Sundays + 52 × 0.5 Saturdays

    // 1–7 June 2026: Mon–Fri working, Sat half, Sun off → 5.5
    expect(app(HolidayResolver::class)->workingDaysBetween($employee, '2026-06-01', '2026-06-07'))->toBe('5.500')
        ->and(app(HolidayResolver::class)->workingDaysBetween($employee, '2026-06-01', '2026-06-07', false))->toBe('6.000');
});

// B8 #36
it('rejects holidays outside the list range and duplicate dates', function () {
    $list = HolidayList::factory()->create();
    $save = app(SaveHoliday::class);
    $save->handle($list, null, ['date' => '2026-08-31', 'name' => 'Merdeka', 'kind' => 'public']);

    expect(fn () => $save->handle($list, null, ['date' => '2027-01-01', 'name' => 'X', 'kind' => 'public']))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($list, null, ['date' => '2026-08-31', 'name' => 'Y', 'kind' => 'organisation']))->toThrow(ValidationException::class);
});

// B8 #37
it('suggests a replacement holiday on the next free working day', function () {
    $list = HolidayList::factory()->create(['weekly_off_days' => [6, 7]]);
    // 2026-08-30 is a Sunday; 2026-08-31 (Mon) is already a holiday → suggestion is Tue 2026-09-01
    app(SaveHoliday::class)->handle($list, null, ['date' => '2026-08-30', 'name' => 'Cuti Contoh', 'kind' => 'state']);
    app(SaveHoliday::class)->handle($list, null, ['date' => '2026-08-31', 'name' => 'Hari Kebangsaan', 'kind' => 'public']);

    $suggestions = app(HolidayListAnalyzer::class)->replacementSuggestions($list->fresh());
    expect($suggestions)->toHaveCount(1)->and($suggestions[0]['suggested_date'])->toBe('2026-09-01');
});

// B8 #38, #39, #40
it('resolves employee over branch over organisation and picks the latest effective assignment', function () {
    $branch = Branch::factory()->create();
    $employee = employee(['branch_id' => $branch->id]);
    app(WorkHistoryRecorder::class)->correctCurrent($employee);
    $orgList = HolidayList::factory()->create(['name' => 'Org']);
    $branchList = HolidayList::factory()->create(['name' => 'Cawangan']);
    $empList = HolidayList::factory()->create(['name' => 'Individu']);
    $resolver = fn () => new HolidayResolver;

    expect(fn () => $resolver()->listFor($employee, '2026-05-01'))->toThrow(NoHolidayListAssigned::class);

    assignList($orgList, 'organisation', $this->org->id, '2026-03-01');
    expect($resolver()->listFor($employee, '2026-05-01')->id)->toBe($orgList->id);
    $fallback = $resolver()->listFor($employee, '2026-01-15');
    expect($fallback->id)->toBe($orgList->id)->and($fallback->resolvedByFallback)->toBeTrue();

    assignList($branchList, 'branch', $branch->id, '2026-01-01');
    expect($resolver()->listFor($employee, '2026-05-01')->id)->toBe($branchList->id);

    assignList($empList, 'employee', $employee->id, '2026-06-01');
    expect($resolver()->listFor($employee, '2026-06-10')->id)->toBe($empList->id)
        ->and($resolver()->listFor($employee, '2026-05-31')->id)->toBe($branchList->id);

    $segments = $resolver()->segments($employee, '2026-05-25', '2026-06-05');
    expect(count($segments))->toBe(2)
        ->and($segments[0]['to']->toDateString())->toBe('2026-05-31')
        ->and($segments[1]['list']->id)->toBe($empList->id);
});

// B8 #41
it('splits segments at a mid-month branch transfer', function () {
    $kuantan = Branch::factory()->create();
    $temerloh = Branch::factory()->create();
    $employee = employee(['branch_id' => $kuantan->id, 'date_of_joining' => '2025-01-01']);
    app(WorkHistoryRecorder::class)->correctCurrent($employee);
    $listK = HolidayList::factory()->create();
    $listT = HolidayList::factory()->create();
    assignList($listK, 'branch', $kuantan->id, '2026-01-01');
    assignList($listT, 'branch', $temerloh->id, '2026-01-01');

    $employee->update(['branch_id' => $temerloh->id]);
    app(WorkHistoryRecorder::class)->transition($employee, CarbonImmutable::parse('2026-06-16'), WorkHistorySource::Movement);

    $segments = (new HolidayResolver)->segments($employee, '2026-06-01', '2026-06-30');
    expect(count($segments))->toBe(2)
        ->and($segments[0]['list']->id)->toBe($listK->id)
        ->and($segments[0]['to']->toDateString())->toBe('2026-06-15')
        ->and($segments[1]['list']->id)->toBe($listT->id);
});

// B8 #42
it('fires HolidayListChanged with added and removed dates', function () {
    Event::fake([HolidayListChanged::class]);
    $list = HolidayList::factory()->create();
    $holiday = app(SaveHoliday::class)->handle($list, null, ['date' => '2026-10-01', 'name' => 'Cuti Peristiwa', 'kind' => 'ad_hoc']);
    app(SaveHoliday::class)->handle($list, $holiday, ['date' => '2026-10-02', 'name' => 'Cuti Peristiwa', 'kind' => 'ad_hoc']);
    app(SaveHoliday::class)->delete($holiday->fresh());

    Event::assertDispatched(HolidayListChanged::class, fn ($e) => $e->addedDates === ['2026-10-01'] && $e->removedDates === []);
    Event::assertDispatched(HolidayListChanged::class, fn ($e) => $e->addedDates === ['2026-10-02'] && $e->removedDates === ['2026-10-01']);
    Event::assertDispatched(HolidayListChanged::class, fn ($e) => $e->removedDates === ['2026-10-02']);
});

it('imports catalogue holidays for the list state and rejects duplicate submitted assignments', function () {
    PublicHoliday::factory()->create(['date' => '2026-08-31', 'scope' => 'federal', 'name_ms' => 'Hari Kebangsaan']);
    PublicHoliday::factory()->create(['date' => '2026-07-30', 'scope' => 'PHG', 'name_ms' => 'Cuti Negeri']);
    PublicHoliday::factory()->create(['date' => '2026-12-11', 'scope' => 'SGR', 'name_ms' => 'Cuti Selangor']);
    $list = HolidayList::factory()->create();

    expect(app(ImportPublicHolidays::class)->handle($list, 2026, ['federal', 'PHG']))->toBe(2)
        ->and(app(ImportPublicHolidays::class)->handle($list, 2026, ['federal', 'PHG']))->toBe(0)
        ->and($list->holidays()->where('kind', 'state')->count())->toBe(1);

    assignList($list, 'organisation', $this->org->id, '2026-01-01');
    expect(fn () => assignList($list, 'organisation', $this->org->id, '2026-01-01'))->toThrow(ValidationException::class);
});

it('renders the holiday screens and bulk-assigns employees', function () {
    $list = HolidayList::factory()->create();
    employee();
    employee();
    actingInOrg($this->hr, $this->org)->get('/holiday-lists')->assertOk();
    $this->get("/holiday-lists/{$list->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Core/HolidayLists/Show'));
    $this->post("/holiday-lists/{$list->id}/weekly-offs")->assertSessionHasNoErrors();
    $this->post('/holiday-assignments/bulk', ['holiday_list_id' => $list->id, 'effective_from' => '2026-02-01'])->assertSessionHasNoErrors();
    $this->get('/holiday-assignments')->assertOk()->assertInertia(fn ($p) => $p->where('assignments.total', 2));
    $this->post("/holiday-lists/{$list->id}/copy", ['name' => 'Senarai 2027'])->assertRedirect();
    expect(HolidayList::query()->where('name', 'Senarai 2027')->first()->from_date->toDateString())->toBe('2027-01-01');
});
