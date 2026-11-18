<?php

/*
| Helpers for the M3 (Kehadiran & Shift) test suite. Loaded from tests/Pest.php.
| Calendar facts used by spec-03 §11: 2026-11-02 is a Monday; Sat/Sun weekly off;
| 2026-12-25 (Friday) is a public holiday.
*/

use App\Domain\Attendance\Enums\CheckinSource;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\AbsenceMarker;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\RosterGenerator;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Actions\GenerateWeeklyOffs;
use App\Domain\Core\Actions\HolidayAssignmentActions;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Core\Services\HolidayResolver;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Organisation + HR manager + approver + PAGI / MALAM shifts + holiday list (Sat–Sun off, 25 Dec public). */
function attendanceWorld(string $now = '2026-11-02 07:00:00', array $halfDayWeeklyOff = []): stdClass
{
    test()->travelTo(CarbonImmutable::parse($now));

    $w = new stdClass;
    $w->org = inOrg(makeOrganisation());
    $w->hr = userIn($w->org, 'pengurus_hr', ['name' => 'Pengurus HR']);
    $w->approver = userIn($w->org, 'kakitangan', ['name' => 'Pelulus']);
    $w->list = attendanceHolidayList($w->hr, $halfDayWeeklyOff);
    $w->pagi = shiftType('PAGI', '08:00', '17:00', ['break_minutes' => 60, 'break_start_time' => '12:00:00', 'early_grace_minutes' => 10]);
    $w->malam = shiftType('MALAM', '22:00', '06:00', ['break_minutes' => 0]);

    return $w;
}

function attendanceHolidayList(User $actor, array $halfDayWeeklyOff = []): HolidayList
{
    $list = HolidayList::factory()->create([
        'from_date' => '2026-01-01', 'to_date' => '2026-12-31',
        'weekly_off_days' => array_values(array_diff([6, 7], $halfDayWeeklyOff)),
        'half_day_weekly_off_days' => $halfDayWeeklyOff,
    ]);
    app(GenerateWeeklyOffs::class)->handle($list);
    Holiday::query()->create(['holiday_list_id' => $list->id, 'date' => '2026-12-25', 'name' => 'Hari Krismas', 'kind' => 'public', 'is_half_day' => false, 'is_mandatory' => true]);
    $hla = HolidayListAssignment::factory()->create(['holiday_list_id' => $list->id, 'effective_from' => '2026-01-01']);
    app(HolidayAssignmentActions::class)->submit($hla, $actor);
    app(HolidayResolver::class)->flush();

    return $list;
}

function shiftType(string $code, string $start, string $end, array $extra = []): ShiftType
{
    return ShiftType::query()->create($extra + [
        'code' => $code, 'name' => 'Shift '.$code, 'start_time' => $start.':00', 'end_time' => $end.':00',
        'open_before_minutes' => 60, 'close_after_minutes' => 120, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10,
        'half_day_threshold_minutes' => 240, 'absent_threshold_minutes' => 120, 'process_from' => '2026-10-01', 'absent_buffer_days' => 1,
    ]);
}

/** Weekly roster: Mon–Fri = $weekday shift, Sat = $saturday (shift or rest), Sun rest; anchored on a Monday. */
function weeklyRoster(Employee $employee, ShiftType $weekday, ShiftType|string $saturday = 'rest', string $anchor = '2026-11-02', ?string $end = '2026-12-31'): void
{
    $schedule = ShiftSchedule::query()->create(['code' => 'W'.$employee->id, 'name' => 'Mingguan', 'cycle_length_days' => 7]);
    foreach (range(0, 6) as $i) {
        $slot = match (true) {
            $i < 5 => ['kind' => 'shift', 'shift_type_id' => $weekday->id],
            $i === 5 => $saturday instanceof ShiftType ? ['kind' => 'shift', 'shift_type_id' => $saturday->id] : ['kind' => $saturday, 'shift_type_id' => null],
            default => ['kind' => 'rest', 'shift_type_id' => null],
        };
        $schedule->days()->create(['day_index' => $i] + $slot);
    }
    app(RosterGenerator::class)->assign(['employee_id' => $employee->id, 'shift_schedule_id' => $schedule->id, 'anchor_date' => $anchor, 'end_date' => $end]);
}

/** A raw punch at a time, resolved like a device punch (geofence not applicable). */
function punchAt(Employee $employee, string $at, CheckinSource $source = CheckinSource::Device): EmployeeCheckin
{
    $time = CarbonImmutable::parse($at);
    $occurrence = app(ShiftCalendar::class)->occurrenceAt($employee, $time);
    $punch = new EmployeeCheckin;
    $punch->forceFill(array_merge($occurrence?->snapshot() ?? ShiftOccurrence::emptySnapshot(), [
        'organisation_id' => $employee->organisation_id,
        'employee_id' => $employee->id,
        'punched_at' => $time,
        'received_at' => $time,
        'source' => $source,
        'geofence_result' => GeofenceResult::NotApplicable,
    ]))->save();

    return $punch;
}

/** Run the cut-off processor (and optionally absent marking) "at" a moment. */
function processAt(string $at, bool $absences = false): void
{
    test()->travelTo(CarbonImmutable::parse($at));
    app(HolidayResolver::class)->flush();
    app(AttendanceProcessor::class)->run();
    if ($absences) {
        app(AbsenceMarker::class)->run();
    }
}

function markAbsencesAt(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at));
    app(AbsenceMarker::class)->run();
}

/** A Kakitangan with a login and a default (optional) shift. */
function worker(?ShiftType $default = null, array $attributes = []): Employee
{
    return staff($attributes + ['default_shift_type_id' => $default?->id]);
}
