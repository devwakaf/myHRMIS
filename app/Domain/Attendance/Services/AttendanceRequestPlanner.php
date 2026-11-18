<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AttendanceRequestKind;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\HalfDayBasis;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Attendance\Enums\RequestDayOutcome;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\AttendanceRequestDay;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use Carbon\CarbonImmutable;

/**
 * Per-day plan of a Permohonan Kehadiran (spec-03 §4.8), used by the live preview
 * (decisionContext), the completion veto and onApprovalCompleted.
 */
final class AttendanceRequestPlanner
{
    public function __construct(
        private readonly DayTypeResolver $dayTypes,
        private readonly ShiftCalendar $calendar,
        private readonly LeaveQuery $leave,
        private readonly PayrollLockQuery $locks,
        private readonly AttendanceWriter $writer,
    ) {}

    /**
     * @return list<array{date: CarbonImmutable, outcome: RequestDayOutcome, existing: Attendance|null, half: bool, day_type: string}>
     */
    public function plan(AttendanceRequest $request): array
    {
        $employee = $request->approvalApplicant();
        $out = [];

        foreach ($request->range()->dates() as $date) {
            $half = $request->is_half_day && $request->half_day_date !== null && $request->half_day_date->isSameDay($date);
            $occurrence = $this->calendar->occurrenceOn($employee, $date);
            $info = $this->dayTypes->for($employee, $date, $occurrence?->shift);
            $rows = Attendance::query()->live()->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date->toDateString())->get();
            $entry = ['date' => $date, 'outcome' => RequestDayOutcome::Created, 'existing' => null, 'half' => $half, 'day_type' => $info->dayType->value];

            if ($this->locks->isLocked($employee, $date)) {
                $out[] = ['outcome' => RequestDayOutcome::SkippedLocked] + $entry;

                continue;
            }
            if (! $info->isNormal() && ! $request->include_rest_days) {
                $out[] = ['outcome' => RequestDayOutcome::SkippedRestOrHoliday] + $entry;

                continue;
            }

            $leaveRow = $rows->first(fn (Attendance $a) => $a->isLeave());
            $leaveDay = $this->leave->approvedLeaveOn($employee, $date);
            if ($leaveRow?->attendance_status === AttendanceStatus::OnLeave || ($leaveRow === null && $leaveDay !== null && $leaveDay->session === 'full')) {
                $out[] = ['outcome' => RequestDayOutcome::SkippedLeave] + $entry;

                continue;
            }
            if ($leaveRow !== null) {
                $out[] = $leaveRow->other_half_status === OtherHalfStatus::Pending || $leaveRow->other_half_status === OtherHalfStatus::Absent
                    ? ['outcome' => RequestDayOutcome::CompletedHalfDay, 'existing' => $leaveRow] + $entry
                    : ['outcome' => RequestDayOutcome::SkippedWorked, 'existing' => $leaveRow] + $entry;

                continue;
            }

            $worked = $rows->first(fn (Attendance $a) => $a->isWorked()
                || in_array($a->attendance_status, [AttendanceStatus::Wfh, AttendanceStatus::OnDuty], true)
                || $a->half_day_basis === HalfDayBasis::Request);
            if ($worked !== null) {
                $out[] = ['outcome' => RequestDayOutcome::SkippedWorked, 'existing' => $worked] + $entry;

                continue;
            }

            $absent = $rows->first(fn (Attendance $a) => $a->attendance_status === AttendanceStatus::Absent);
            if ($absent !== null) {
                $out[] = ['outcome' => $absent->source === AttendanceSource::Auto ? RequestDayOutcome::ReplacedAbsent : RequestDayOutcome::Created, 'existing' => $absent] + $entry;

                continue;
            }

            $other = $rows->first();
            $out[] = ['existing' => $other] + $entry;
        }

        return $out;
    }

    /**
     * True when every day would be skipped.
     *
     * @param  list<array{outcome: RequestDayOutcome}>  $plan
     */
    public static function nothingToApply(array $plan): bool
    {
        foreach ($plan as $day) {
            if (in_array($day['outcome'], [RequestDayOutcome::Created, RequestDayOutcome::ReplacedAbsent, RequestDayOutcome::CompletedHalfDay], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply the plan (onApprovalCompleted): rows created / superseded / completed and
     * one attendance_request_days row per date.
     */
    public function apply(AttendanceRequest $request): void
    {
        $employee = $request->approvalApplicant();
        $status = $request->kind === AttendanceRequestKind::Wfh ? AttendanceStatus::Wfh : AttendanceStatus::OnDuty;
        $otherHalf = $request->kind === AttendanceRequestKind::Wfh ? OtherHalfStatus::Wfh : OtherHalfStatus::OnDuty;

        foreach ($this->plan($request) as $day) {
            $attendanceId = null;
            $supersededId = null;

            if ($day['outcome'] === RequestDayOutcome::CompletedHalfDay && $day['existing'] !== null) {
                $this->writer->update($day['existing'], ['other_half_status' => $otherHalf]);
                $attendanceId = $day['existing']->id;
            } elseif (in_array($day['outcome'], [RequestDayOutcome::Created, RequestDayOutcome::ReplacedAbsent], true)) {
                $attributes = $this->rowAttributes($request, $employee, $day['date'], $day['half'], $status);
                if ($day['existing'] !== null) {
                    $row = $this->writer->supersede($day['existing'], $attributes);
                    $supersededId = $day['existing']->id;
                } else {
                    $row = $this->writer->create($attributes);
                }
                $attendanceId = $row->id;
            }

            AttendanceRequestDay::query()->create([
                'attendance_request_id' => $request->id,
                'work_date' => $day['date']->toDateString(),
                'outcome' => $day['outcome'],
                'attendance_id' => $attendanceId,
                'superseded_attendance_id' => $supersededId,
            ]);
        }
    }

    /** Revert after cancelling an approved request: cancel created rows, restore superseded ones. */
    public function revert(AttendanceRequest $request): void
    {
        foreach ($request->days()->get() as $day) {
            if ($day->attendance_id === null) {
                continue;
            }
            $row = Attendance::query()->find($day->attendance_id);
            if ($row === null || ! $row->isLive()) {
                continue;
            }
            if ($day->outcome === RequestDayOutcome::CompletedHalfDay) {
                $this->writer->update($row, ['other_half_status' => OtherHalfStatus::Pending]);

                continue;
            }
            $this->writer->cancel($row, null, 'request_cancelled:'.$request->number);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(AttendanceRequest $request, Employee $employee, CarbonImmutable $date, bool $half, AttendanceStatus $status): array
    {
        $occurrence = $this->calendar->occurrenceOn($employee, $date);
        $info = $this->dayTypes->for($employee, $date, $occurrence?->shift);

        return [
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'attendance_date' => $date->toDateString(),
            'attendance_status' => $half ? AttendanceStatus::HalfDay : $status,
            'half_day_basis' => $half ? HalfDayBasis::Request : null,
            'half_day_session' => $half ? $request->half_day_session : null,
            'other_half_status' => $half ? OtherHalfStatus::Pending : null,
            'day_type' => $info->dayType,
            'is_half_day_off' => $info->isHalfDayOff,
            'holiday_label' => $info->label,
            'shift_type_id' => $request->shift_type_id ?? $occurrence?->shift->id,
            'shift_assignment_id' => $occurrence?->assignment?->id,
            'scheduled_start_at' => $occurrence?->start,
            'scheduled_end_at' => $occurrence?->end,
            'normal_minutes' => $occurrence === null ? null : (int) $occurrence->shift->normal_minutes,
            'source' => AttendanceSource::Request,
            'attendance_request_id' => $request->id,
        ];
    }
}
