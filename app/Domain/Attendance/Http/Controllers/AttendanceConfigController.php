<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Actions\AttendanceConfigActions;
use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\LocationLinkType;
use App\Domain\Attendance\Enums\NoLocationPolicy;
use App\Domain\Attendance\Enums\OtEligibilityMode;
use App\Domain\Attendance\Enums\OutsideGeofenceAction;
use App\Domain\Attendance\Enums\OvertimeBasis;
use App\Domain\Attendance\Enums\ScheduleDayKind;
use App\Domain\Attendance\Enums\SinglePunchPolicy;
use App\Domain\Attendance\Enums\WithinNormalMode;
use App\Domain\Attendance\Http\AttendancePresenter;
use App\Domain\Attendance\Http\Requests\AttendanceProfileRequest;
use App\Domain\Attendance\Http\Requests\AttendanceSettingsRequest;
use App\Domain\Attendance\Http\Requests\OvertimeTypeRequest;
use App\Domain\Attendance\Http\Requests\ShiftLocationRequest;
use App\Domain\Attendance\Http\Requests\ShiftScheduleRequest;
use App\Domain\Attendance\Http\Requests\ShiftTypeRequest;
use App\Domain\Attendance\Models\AktaKerjaParameter;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HolidayList;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kehadiran configuration (spec-03 §2.5, §2.7, §2.8, §2.14, §2.15): Tetapan,
 * Jenis Shift, Lokasi Shift, Corak Roster, Jenis OT and the employee profile tab.
 * Read with attendance.config.view, write with attendance.config.manage.
 */
final class AttendanceConfigController extends Controller
{
    public function __construct(private readonly AttendanceConfigActions $actions) {}

    public function settings(): Response
    {
        $this->requirePermission('attendance.config.manage', 'attendance.config.view');
        $params = AktaKerjaParameter::effectiveOn(CarbonImmutable::today());

        return Inertia::render('Attendance/Settings', [
            'settings' => AttendancePresenter::settings(AttendanceSettings::current()),
            'akta_kerja' => [
                'monthly_ot_cap_hours' => (string) $params->monthly_ot_cap_hours,
                'ot_wage_threshold' => (string) $params->ot_wage_threshold,
                'verified' => $params->verified_at !== null,
            ],
            'options' => [
                'no_location_policies' => NoLocationPolicy::options(),
                'outside_geofence_actions' => OutsideGeofenceAction::options(),
                'day_types' => DayType::options(),
                'overtime_types' => OvertimeType::query()->where('is_active', true)->get(['id', 'code', 'name'])->toArray(),
            ],
            'can' => ['manage' => request()->user()->can('attendance.config.manage')],
        ]);
    }

    public function updateSettings(AttendanceSettingsRequest $request): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $data = $request->safe()->except('bump_notice_version');
        $this->actions->saveSettings($data, $request->boolean('bump_notice_version'));

        return $this->done('common.saved');
    }

    public function shiftTypes(): Response
    {
        $this->requirePermission('attendance.config.manage', 'attendance.config.view');

        return Inertia::render('Attendance/ShiftTypes/Index', [
            'shift_types' => ShiftType::query()->orderBy('code')->get()->map(fn (ShiftType $s) => AttendancePresenter::shiftType($s))->values()->all(),
            'options' => [
                'single_punch_policies' => SinglePunchPolicy::options(),
                'holiday_lists' => HolidayList::query()->orderByDesc('from_date')->get(['id', 'name'])->toArray(),
                'overtime_types' => OvertimeType::query()->where('is_active', true)->get(['id', 'code', 'name'])->toArray(),
            ],
            'can' => ['manage' => request()->user()->can('attendance.config.manage')],
        ]);
    }

    /** Akta Kerja warnings for an unsaved form (JSON). */
    public function checkShiftType(ShiftTypeRequest $request): JsonResponse
    {
        $shift = new ShiftType($request->validated());
        [$errors, $warnings] = AttendanceConfigActions::checkShift($shift);

        return response()->json(['errors' => $errors, 'warnings' => $warnings, 'normal_minutes' => max(0, $shift->spanMinutes() - (int) $shift->break_minutes)]);
    }

    public function storeShiftType(ShiftTypeRequest $request): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $result = $this->actions->saveShiftType(null, $request->validated());

        return $this->done('common.saved', $result['warnings']);
    }

    public function updateShiftType(ShiftTypeRequest $request, ShiftType $shiftType): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $result = $this->actions->saveShiftType($shiftType, $request->validated());

        return $this->done('common.saved', $result['warnings']);
    }

    public function locations(Request $request): Response
    {
        $this->requirePermission('attendance.config.manage', 'attendance.config.view');

        return Inertia::render('Attendance/Locations/Index', [
            'locations' => ShiftLocation::query()->with('links')->orderBy('name')->get()
                ->map(fn (ShiftLocation $l) => AttendancePresenter::location($l))->values()->all(),
            'options' => [
                'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->toArray(),
                'employees' => Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])->toArray(),
                'link_types' => LocationLinkType::options(),
            ],
            'can' => ['manage' => $request->user()->can('attendance.config.manage')],
        ]);
    }

    public function storeLocation(ShiftLocationRequest $request): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $this->actions->saveLocation(null, $request->safe()->except('links'), $request->validated('links'));

        return $this->done('common.saved');
    }

    public function updateLocation(ShiftLocationRequest $request, ShiftLocation $location): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $this->actions->saveLocation($location, $request->safe()->except('links'), $request->validated('links'));

        return $this->done('common.saved');
    }

    public function schedules(Request $request): Response
    {
        $this->requirePermission('attendance.config.manage', 'attendance.config.view');

        return Inertia::render('Attendance/Schedules/Index', [
            'schedules' => ShiftSchedule::query()->with('days')->orderBy('code')->get()
                ->map(fn (ShiftSchedule $s) => AttendancePresenter::schedule($s))->values()->all(),
            'options' => [
                'kinds' => ScheduleDayKind::options(),
                'shift_types' => ShiftType::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'colour'])->toArray(),
            ],
            'can' => ['manage' => $request->user()->can('attendance.config.manage')],
        ]);
    }

    public function storeSchedule(ShiftScheduleRequest $request): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $this->actions->saveSchedule(null, $request->safe()->except('days'), $request->validated('days'));

        return $this->done('common.saved');
    }

    public function updateSchedule(ShiftScheduleRequest $request, ShiftSchedule $schedule): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $this->actions->saveSchedule($schedule, $request->safe()->except('days'), $request->validated('days'));

        return $this->done('common.saved');
    }

    public function overtimeTypes(Request $request): Response
    {
        $this->requirePermission('attendance.config.manage', 'attendance.config.view');

        return Inertia::render('Attendance/OvertimeTypes/Index', [
            'overtime_types' => OvertimeType::query()->with('rules')->orderBy('code')->get()
                ->map(fn (OvertimeType $t) => AttendancePresenter::overtimeType($t))->values()->all(),
            'options' => [
                'bases' => OvertimeBasis::options(),
                'day_types' => DayType::options(),
                'within_normal_modes' => WithinNormalMode::options(),
            ],
            'can' => ['manage' => $request->user()->can('attendance.config.manage')],
        ]);
    }

    public function storeOvertimeType(OvertimeTypeRequest $request): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $this->actions->saveOvertimeType(null, $request->safe()->except('rules'), $request->validated('rules'));

        return $this->done('common.saved');
    }

    public function updateOvertimeType(OvertimeTypeRequest $request, OvertimeType $overtimeType): RedirectResponse
    {
        $this->requirePermission('attendance.config.manage');
        $this->actions->saveOvertimeType($overtimeType, $request->safe()->except('rules'), $request->validated('rules'));

        return $this->done('common.saved');
    }

    /** Employee page tab "Kehadiran" (JSON read). */
    public function profile(Employee $employee): JsonResponse
    {
        $this->requirePermission('attendance.manage', 'attendance.view-all');
        $profile = EmployeeAttendanceProfile::for($employee);

        return response()->json([
            'profile' => [
                'checkin_required' => $profile->checkin_required,
                'geofence_exempt' => $profile->geofence_exempt,
                'is_manual_labour' => $profile->is_manual_labour,
                'ot_eligibility' => $profile->ot_eligibility->value,
                'ot_eligibility_note' => $profile->ot_eligibility_note,
                'device_user_code' => $profile->device_user_code,
                'default_shift_type_id' => $employee->default_shift_type_id,
            ],
            'options' => [
                'ot_eligibility' => OtEligibilityMode::options(),
                'shift_types' => ShiftType::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray(),
            ],
        ]);
    }

    public function updateProfile(AttendanceProfileRequest $request, Employee $employee): RedirectResponse
    {
        $this->requirePermission('attendance.manage');
        $data = $request->validated();
        if (array_key_exists('default_shift_type_id', $data)) {
            $employee->forceFill(['default_shift_type_id' => $data['default_shift_type_id']])->save();
            unset($data['default_shift_type_id']);
        }
        $this->actions->saveProfile($employee, $data);

        return $this->done('common.saved');
    }
}
