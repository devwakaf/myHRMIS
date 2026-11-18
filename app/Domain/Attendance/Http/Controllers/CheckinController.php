<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Actions\RecordLocationConsent;
use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Contracts\CheckinIngestor;
use App\Domain\Attendance\Enums\CheckinSource;
use App\Domain\Attendance\Http\AttendancePresenter;
use App\Domain\Attendance\Http\Requests\CheckinRequest;
use App\Domain\Attendance\Http\Requests\ConsentRequest;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\LocationConsent;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Attendance\Support\PunchData;
use App\Domain\Core\Models\Employee;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service: Check-in page (spec-03 §2.1) and Kehadiran Saya (§2.2).
 * The server clock is authoritative; the page gets server_now for its display clock.
 */
final class CheckinController extends Controller
{
    public function show(Request $request, ShiftCalendar $calendar): Response
    {
        $employee = $this->ownEmployee($request);
        $now = CarbonImmutable::now();
        $settings = AttendanceSettings::current();
        $profile = EmployeeAttendanceProfile::for($employee);
        $occurrence = $calendar->occurrenceAt($employee, $now) ?? $calendar->occurrenceOn($employee, $now);
        $consent = LocationConsent::latestFor($employee->id);
        $last = EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)->with('location')->orderByDesc('punched_at')->first();
        $inWindow = $occurrence !== null && $last !== null && $last->shift_type_id === $occurrence->shift->id
            && $last->shift_date?->isSameDay($occurrence->date);

        return Inertia::render('Attendance/Checkin', [
            'server_now' => $now->toIso8601String(),
            'employee' => AttendancePresenter::employee($employee),
            'shift' => $occurrence === null ? null : [
                'code' => $occurrence->shift->code,
                'name' => $occurrence->shift->name,
                'time' => $occurrence->shift->timeLabel(),
                'date' => $occurrence->date->toDateString(),
                'window_start' => $occurrence->windowStart->toIso8601String(),
                'window_end' => $occurrence->windowEnd->toIso8601String(),
            ],
            'last_punch' => $last === null ? null : AttendancePresenter::checkin($last, true),
            'next_action' => $inWindow ? 'checkout' : 'checkin',
            'geofence' => [
                'required' => $settings->geofence_enabled && ! $profile->geofence_exempt,
                'request_location' => $settings->geofence_enabled,
            ],
            'consent' => [
                'notice_version' => $settings->location_notice_version,
                'retention_months' => $settings->location_retention_months,
                'accepted' => $consent !== null && $consent->isValidFor($settings->location_notice_version),
                'decided_at' => $consent?->decided_at->toIso8601String(),
            ],
            'checkin_required' => $profile->checkin_required,
        ]);
    }

    public function store(CheckinRequest $request, CheckinIngestor $ingestor): JsonResponse
    {
        abort_unless($request->user()->can('attendance.checkin'), 403);
        $employee = $this->ownEmployee($request);
        $data = $request->validated();

        $outcome = $ingestor->ingest($employee, new PunchData(
            source: $request->boolean('standalone') ? CheckinSource::Mobile : CheckinSource::Web,
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            accuracy: isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            clientReportedAt: isset($data['client_time']) ? CarbonImmutable::parse($data['client_time'])->setTimezone(config('app.timezone')) : null,
            clientRequestId: $data['client_request_id'] ?? null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));
        $punch = $outcome->checkin->load('location');

        return response()->json([
            'message' => __($outcome->duplicate ? 'attendance.checkin.already_recorded' : 'attendance.checkin.recorded'),
            'duplicate' => $outcome->duplicate,
            'server_time' => $punch->punched_at->toIso8601String(),
            'log_type' => $punch->log_type?->value,
            'location' => $punch->location?->name,
            'distance_m' => $punch->distance_m === null ? null : (int) round((float) $punch->distance_m),
            'geofence_result' => $punch->geofence_result->value,
        ], $outcome->duplicate ? 200 : 201);
    }

    public function consent(ConsentRequest $request, RecordLocationConsent $action): RedirectResponse|JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $consent = $action->decide($employee, $request->boolean('accepted'), $request->userAgent());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['accepted' => $consent->accepted, 'notice_version' => $consent->notice_version]);
        }

        return $this->done('common.saved');
    }

    public function withdrawConsent(Request $request, RecordLocationConsent $action): RedirectResponse
    {
        $action->withdraw($this->ownEmployee($request));

        return $this->done('attendance.messages.consent_withdrawn');
    }

    /** Kehadiran Saya — month view. */
    public function me(Request $request, AttendanceForPayroll $payroll, DayTypeResolver $dayTypes, ShiftCalendar $calendar): Response
    {
        $employee = $this->ownEmployee($request);
        $month = CarbonImmutable::parse((string) $request->query('month', today()->format('Y-m-01')))->startOfMonth();
        $end = $month->endOfMonth();

        $rows = Attendance::query()->live()->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$month->toDateString(), $end->toDateString()])
            ->with('shiftType')->orderBy('attendance_date')->get();
        $punches = EmployeeCheckin::query()->where('employee_id', $employee->id)->notVoided()
            ->whereBetween('punched_at', [$month->subDay()->startOfDay(), $end->addDay()->endOfDay()])
            ->with('location')->orderBy('punched_at')->get();

        $days = [];
        for ($d = $month; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $occurrence = $calendar->occurrenceOn($employee, $d);
            $info = $dayTypes->for($employee, $d, $occurrence?->shift);
            $days[] = [
                'date' => $d->toDateString(),
                'day_type' => $info->dayType->value,
                'is_half_day_off' => $info->isHalfDayOff,
                'holiday_label' => $info->label,
                'shift' => $occurrence === null ? null : ['code' => $occurrence->shift->code, 'time' => $occurrence->shift->timeLabel(), 'colour' => $occurrence->shift->colour],
            ];
        }

        return Inertia::render('Attendance/Me', [
            'employee' => AttendancePresenter::employee($employee),
            'month' => $month->format('Y-m'),
            'days' => $days,
            'attendances' => $rows->map(fn (Attendance $a) => AttendancePresenter::attendance($a))->values()->all(),
            'checkins' => $punches->map(fn (EmployeeCheckin $c) => AttendancePresenter::checkin($c, true))->values()->all(),
            'summary' => $payroll->periodSummary($employee, $month, $end)->toArray(),
            'requests' => AttendanceRequest::query()->where('employee_id', $employee->id)
                ->whereDate('from_date', '<=', $end->toDateString())->whereDate('to_date', '>=', $month->toDateString())
                ->get()->map(fn (AttendanceRequest $r) => AttendancePresenter::attendanceRequest($r))->values()->all(),
            'can' => [
                'request' => $request->user()->can('attendance.request.create'),
                'shift_request' => $request->user()->can('attendance.shift-request.create'),
            ],
        ]);
    }

    private function ownEmployee(Request $request): Employee
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        abort_if($employee === null, 403, (string) __('attendance.checkin.errors.no_employee'));

        return $employee;
    }
}
