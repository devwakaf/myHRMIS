<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Adapters;

use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\HalfDayBasis;
use App\Domain\Attendance\Enums\HalfDaySession;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Exceptions\LeaveAttendanceConflict;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Notifications\LockedDatesSkipped;
use App\Domain\Attendance\Services\AttendanceWriter;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Enums\DaySession;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveApplicationDay;
use App\Domain\Leave\Support\WorkedDay;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Attendance's implementation of Leave's port (spec-02 §5.1, spec-03 §5.1).
 * Runs inside Leave's engine transaction: an exception rolls the approval back.
 * Worked / WFH / Tugas Luar rows are never overwritten (LeaveAttendanceConflict);
 * locked payroll dates are skipped with an HR notice.
 */
final class LeaveAttendanceAdapter implements LeaveAttendancePort
{
    public function __construct(
        private readonly AttendanceWriter $writer,
        private readonly ShiftCalendar $calendar,
        private readonly DayTypeResolver $dayTypes,
        private readonly PayrollLockQuery $locks,
        private readonly HrRecipients $hr,
    ) {}

    public function markLeave(LeaveApplication $application): void
    {
        $application->loadMissing('days');
        $employee = $application->approvalApplicant();
        $skipped = [];
        $conflicts = [];
        $plans = [];

        foreach ($application->days as $day) {
            if ((float) $day->portion <= 0) {
                continue;
            }
            if ($this->locks->isLocked($employee, $day->date)) {
                $skipped[] = $day->date->format('d/m/Y');

                continue;
            }

            $rows = $this->liveRows($employee, $day->date);
            if ($rows->contains(fn (Attendance $a) => $a->leave_application_id === $application->id)) {
                continue; // idempotent
            }

            $leaveSession = $day->session;
            $blocking = null;
            $otherHalfWorked = null;
            foreach ($rows as $row) {
                $worked = self::workedSession($row);
                if ($worked === null) {
                    continue;
                }
                if ($leaveSession !== DaySession::Full && $worked !== 'full' && $worked !== $leaveSession->value) {
                    $otherHalfWorked = $row;

                    continue;
                }
                $blocking = $row;
            }
            if ($blocking !== null) {
                $conflicts[] = $day->date->format('d/m/Y');

                continue;
            }

            $plans[] = [$day, $rows, $otherHalfWorked];
        }

        if ($conflicts !== []) {
            throw LeaveAttendanceConflict::forDates($conflicts);
        }

        foreach ($plans as [$day, $rows, $otherHalfWorked]) {
            $this->markDay($application, $employee, $day, $rows->all(), $otherHalfWorked);
        }

        $this->noticeLocked($application, $employee, $skipped);
    }

    public function unmarkLeave(LeaveApplication $application): void
    {
        $employee = $application->approvalApplicant();
        $skipped = [];

        $rows = Attendance::query()->live()->where('leave_application_id', $application->id)->orderBy('attendance_date')->get();
        foreach ($rows as $row) {
            if ($this->locks->isLocked($employee, $row->attendance_date)) {
                $skipped[] = $row->attendance_date->format('d/m/Y');

                continue;
            }

            $restored = $this->writer->cancel($row, null, 'leave_cancelled:'.$application->number);
            $punches = EmployeeCheckin::query()->notVoided()->where('attendance_id', $row->id)->get();
            if ($restored === null && $punches->isNotEmpty()) {
                foreach ($punches as $punch) {
                    $punch->forceFill(['processing_state' => ProcessingState::Pending, 'attendance_id' => null, 'review_reason' => null])->save();
                }
            } elseif ($restored !== null) {
                foreach ($punches as $punch) {
                    $punch->forceFill(['attendance_id' => $restored->id])->save();
                }
            }
        }

        // Punches that were sent to review because of this leave are reprocessed.
        EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
            ->where('processing_state', ProcessingState::NeedsReview->value)->where('review_reason', 'worked_on_leave')
            ->whereBetween('shift_date', [$application->from_date->toDateString(), $application->to_date->toDateString()])
            ->update(['processing_state' => ProcessingState::Pending->value, 'review_reason' => null]);

        $this->noticeLocked($application, $employee, $skipped);
    }

    public function conflicts(Employee $employee, DateRange $range): array
    {
        $rows = Attendance::query()->live()->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $range->from->toDateString())
            ->whereDate('attendance_date', '<=', ($range->to ?? $range->from)->toDateString())
            ->orderBy('attendance_date')->get();

        $out = [];
        foreach ($rows as $row) {
            $session = self::workedSession($row);
            if ($session !== null) {
                $out[] = ['date' => $row->attendance_date->toDateString(), 'session' => $session, 'attendance_status' => $row->attendance_status->value];
            }
        }

        return $out;
    }

    public function workedOn(Employee $employee, CarbonImmutable $date): ?WorkedDay
    {
        $row = $this->liveRows($employee, $date)->first(fn (Attendance $a) => in_array($a->attendance_status, [
            AttendanceStatus::Present, AttendanceStatus::HalfDay, AttendanceStatus::Wfh, AttendanceStatus::OnDuty,
        ], true) && ! ($a->attendance_status === AttendanceStatus::HalfDay && $a->half_day_basis === HalfDayBasis::Leave));

        if ($row === null) {
            return null;
        }

        return new WorkedDay($date, $row->attendance_status->value, true, $row->attendance_status === AttendanceStatus::HalfDay);
    }

    /**
     * Which part of the day a row shows as worked / on WFH or duty: full, am, pm, or null.
     */
    public static function workedSession(Attendance $row): ?string
    {
        if (in_array($row->attendance_status, [AttendanceStatus::Wfh, AttendanceStatus::OnDuty], true)) {
            return 'full';
        }
        if ($row->attendance_status === AttendanceStatus::HalfDay && $row->half_day_basis === HalfDayBasis::Request) {
            return $row->half_day_session->value ?? 'full';
        }
        if (! $row->isWorked()) {
            return null;
        }
        if ($row->half_day_session !== null) {
            return $row->half_day_session->value;
        }
        if ($row->scheduled_start_at === null || $row->scheduled_end_at === null || $row->in_at === null || $row->out_at === null) {
            return 'full';
        }

        // Infer the worked half from the punches relative to the shift midpoint (30 min tolerance).
        $mid = $row->scheduled_start_at->addSeconds(intdiv((int) $row->scheduled_start_at->diffInSeconds($row->scheduled_end_at), 2));
        if ($row->out_at->lessThanOrEqualTo($mid->addMinutes(30))) {
            return 'am';
        }
        if ($row->in_at->greaterThanOrEqualTo($mid->subMinutes(30))) {
            return 'pm';
        }

        return 'full';
    }

    /**
     * @param  list<Attendance>  $rows
     */
    private function markDay(LeaveApplication $application, Employee $employee, LeaveApplicationDay $day, array $rows, ?Attendance $otherHalfWorked): void
    {
        $occurrence = $this->calendar->occurrenceOn($employee, $day->date);
        $info = $this->dayTypes->for($employee, $day->date, $occurrence?->shift);
        $isFull = $day->session === DaySession::Full;

        $attributes = [
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'attendance_date' => $day->date->toDateString(),
            'attendance_status' => $isFull ? AttendanceStatus::OnLeave : AttendanceStatus::HalfDay,
            'half_day_basis' => $isFull ? null : HalfDayBasis::Leave,
            'half_day_session' => $isFull ? null : HalfDaySession::from($day->session->value),
            'other_half_status' => $isFull ? null : OtherHalfStatus::Pending,
            'day_type' => $info->dayType,
            'is_half_day_off' => $info->isHalfDayOff,
            'holiday_label' => $info->label,
            'shift_type_id' => $occurrence?->shift->id,
            'shift_assignment_id' => $occurrence?->assignment?->id,
            'scheduled_start_at' => $occurrence?->start,
            'scheduled_end_at' => $occurrence?->end,
            'normal_minutes' => $occurrence === null ? null : (int) $occurrence->shift->normal_minutes,
            'source' => AttendanceSource::Leave,
            'leave_application_id' => $application->id,
            'leave_type_id' => $application->leave_type_id,
        ];

        if ($otherHalfWorked !== null) {
            // A worked row of the other session is kept and becomes the other half.
            $attributes = array_merge($attributes, [
                'other_half_status' => OtherHalfStatus::Present,
                'in_at' => $otherHalfWorked->in_at,
                'out_at' => $otherHalfWorked->out_at,
                'punch_count' => $otherHalfWorked->punch_count,
                'worked_minutes' => $otherHalfWorked->worked_minutes,
                'shift_type_id' => $otherHalfWorked->shift_type_id ?? $attributes['shift_type_id'],
            ]);
            $new = $this->writer->supersede($otherHalfWorked, $attributes);
            EmployeeCheckin::query()->where('attendance_id', $otherHalfWorked->id)->update(['attendance_id' => $new->id]);

            return;
        }

        $replaceable = null;
        foreach ($rows as $row) {
            if (! $row->isLeave() || $row->leave_application_id !== $application->id) {
                $replaceable = $row; // absent (auto / manual) or another non-worked row
                break;
            }
        }

        if ($replaceable !== null) {
            $this->writer->supersede($replaceable, $attributes);

            return;
        }

        $this->writer->create($attributes);
    }

    /**
     * @return Collection<int, Attendance>
     */
    private function liveRows(Employee $employee, CarbonImmutable $date)
    {
        return Attendance::query()->live()->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())->get();
    }

    /**
     * @param  list<string>  $dates
     */
    private function noticeLocked(LeaveApplication $application, Employee $employee, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        activity('attendance')->performedOn($application)->event('locked_dates_skipped')
            ->withProperties(['dates' => $dates])->log('locked_dates_skipped');

        $recipients = $this->hr->for((int) $employee->organisation_id);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new LockedDatesSkipped($employee->full_name, (string) $application->number, $dates));
        }
    }
}
