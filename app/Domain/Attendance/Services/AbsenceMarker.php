<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Absent marking (spec-03 §4.5), daily 01:30 and after "Proses Sekarang".
 * Idempotent: a re-run creates nothing new. Never marks rest days or holidays.
 */
final class AbsenceMarker
{
    public function __construct(
        private readonly ShiftCalendar $calendar,
        private readonly DayTypeResolver $dayTypes,
        private readonly AttendanceWriter $writer,
        private readonly LeaveQuery $leave,
        private readonly PayrollLockQuery $locks,
    ) {}

    /**
     * Run for the active organisation.
     *
     * @return array{absent: int, half_closed: int}
     */
    public function run(?CarbonImmutable $now = null, ?int $employeeId = null): array
    {
        $now ??= CarbonImmutable::now();
        $settings = AttendanceSettings::current();
        $today = $now->startOfDay();
        $created = 0;

        $lookbackStart = $today->subDays($settings->absent_lookback_days)->toDateString();
        $employees = Employee::query()
            ->where(fn ($q) => $q->notLeft()->orWhereDate('relieving_date', '>=', $lookbackStart))
            ->when($employeeId !== null, fn ($q) => $q->whereKey($employeeId))
            ->get();

        foreach ($employees as $employee) {
            if (! EmployeeAttendanceProfile::for($employee)->checkin_required) {
                continue;
            }

            $from = $today->subDays($settings->absent_lookback_days)->max($employee->date_of_joining);
            $to = $today->subDay();
            if ($employee->relieving_date !== null) {
                $to = $to->min($employee->relieving_date);
            }

            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                if ($this->markDay($employee, $d, $now)) {
                    $created++;
                }
            }
        }

        return ['absent' => $created, 'half_closed' => $this->closePendingHalves($now, $employeeId)];
    }

    private function markDay(Employee $employee, CarbonImmutable $date, CarbonImmutable $now): bool
    {
        $occurrence = $this->calendar->occurrenceOn($employee, $date);
        if ($occurrence === null) {
            return false; // no shift → not expected (counted as unmarked in summaries)
        }

        $shift = $occurrence->shift;
        if (! $shift->auto_attendance || ($shift->process_from !== null && $date->lessThan($shift->process_from))) {
            return false;
        }
        if ($occurrence->windowEnd->addDays((int) $shift->absent_buffer_days)->greaterThan($now)) {
            return false;
        }

        $day = $this->dayTypes->for($employee, $date, $shift);
        if (! $day->isNormal()) {
            return false;
        }

        if ($this->hasBlocker($employee, $date)) {
            return false;
        }

        return DB::transaction(function () use ($employee, $date, $occurrence, $day): bool {
            Employee::query()->lockForUpdate()->find($employee->id);
            if ($this->hasBlocker($employee, $date)) {
                return false;
            }

            $this->writer->create([
                'organisation_id' => $employee->organisation_id,
                'employee_id' => $employee->id,
                'attendance_date' => $date->toDateString(),
                'attendance_status' => AttendanceStatus::Absent,
                'day_type' => $day->dayType,
                'is_half_day_off' => $day->isHalfDayOff,
                'holiday_label' => $day->label,
                'shift_type_id' => $occurrence->shift->id,
                'shift_assignment_id' => $occurrence->assignment?->id,
                'scheduled_start_at' => $occurrence->start,
                'scheduled_end_at' => $occurrence->end,
                'normal_minutes' => $day->isHalfDayOff ? intdiv((int) $occurrence->shift->normal_minutes, 2) : (int) $occurrence->shift->normal_minutes,
                'worked_minutes' => 0,
                'source' => AttendanceSource::Auto,
            ]);

            return true;
        });
    }

    private function hasBlocker(Employee $employee, CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        if (Attendance::query()->live()->where('employee_id', $employee->id)->whereDate('attendance_date', $day)->exists()) {
            return true;
        }
        if ($this->leave->approvedLeaveOn($employee, $date) !== null) {
            return true;
        }
        if ($this->locks->isLocked($employee, $date)) {
            return true;
        }

        $openPunch = EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
            ->whereIn('processing_state', [ProcessingState::Pending->value, ProcessingState::NeedsReview->value])
            ->where(fn ($q) => $q->whereDate('shift_date', $day)
                ->orWhere(fn ($q) => $q->whereNull('shift_date')->whereDate('punched_at', $day)))
            ->exists();
        if ($openPunch) {
            return true;
        }

        return AttendanceRequest::query()->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->whereDate('from_date', '<=', $day)->whereDate('to_date', '>=', $day)
            ->exists();
    }

    /**
     * Half days whose other half is still pending after the buffer → other half absent.
     */
    private function closePendingHalves(CarbonImmutable $now, ?int $employeeId): int
    {
        $closed = 0;
        $rows = Attendance::query()->live()
            ->where('attendance_status', AttendanceStatus::HalfDay->value)
            ->where('other_half_status', OtherHalfStatus::Pending->value)
            ->whereDate('attendance_date', '<', $now->toDateString())
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->get();

        foreach ($rows as $row) {
            $employee = Employee::query()->find($row->employee_id);
            if ($employee === null) {
                continue;
            }
            $occurrence = $row->shift_type_id !== null && $row->shiftType !== null
                ? ShiftOccurrence::build($row->shiftType, null, $row->attendance_date)
                : $this->calendar->occurrenceOn($employee, $row->attendance_date);
            $buffer = (int) ($occurrence?->shift->absent_buffer_days ?? 1);
            $windowEnd = $occurrence->windowEnd ?? $row->attendance_date->endOfDay();
            if ($windowEnd->addDays($buffer)->greaterThan($now)) {
                continue;
            }

            $pending = EmployeeCheckin::query()->notVoided()->where('employee_id', $row->employee_id)
                ->where('processing_state', ProcessingState::Pending->value)
                ->whereDate('shift_date', $row->attendance_date->toDateString())->exists();
            if ($pending || $this->locks->isLocked($employee, $row->attendance_date)) {
                continue;
            }

            $this->writer->update($row, ['other_half_status' => OtherHalfStatus::Absent]);
            $closed++;
        }

        return $closed;
    }
}
