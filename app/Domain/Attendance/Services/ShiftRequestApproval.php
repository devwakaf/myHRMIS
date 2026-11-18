<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AssignmentChangeCause;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Enums\ShiftAssignmentSource;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftRequest;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The `shift_request` Approvable hooks (spec-03 §4.8, §4.12). The only effect is
 * ShiftAssignmentWriter::replaceRange in onApprovalCompleted; cancelling an
 * approved request reverses exactly that change-set.
 */
final class ShiftRequestApproval
{
    public function __construct(
        private readonly ShiftCalendar $calendar,
        private readonly ShiftAssignmentWriter $writer,
        private readonly PayrollLockQuery $locks,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(ShiftRequest $r): array
    {
        $shift = ShiftType::query()->findOrFail($r->shift_type_id);

        return [
            'shift_request.days' => (int) $r->from_date->diffInDays($r->to_date) + 1,
            'shift_request.shift_type_code' => $shift->code,
            'shift_request.is_overnight' => $shift->isOvernight(),
        ];
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}
     */
    public function summary(ShiftRequest $r): array
    {
        $shift = ShiftType::query()->find($r->shift_type_id);

        return [
            'key' => 'attendance.summary.shift_request',
            'params' => ['shift' => (string) $shift?->code, 'from' => $r->from_date->format('d/m/Y'), 'to' => $r->to_date->format('d/m/Y')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decisionContext(ShiftRequest $r, User $viewer): array
    {
        $employee = $r->approvalApplicant();
        $shift = ShiftType::query()->find($r->shift_type_id);
        $days = [];
        foreach ($r->range()->dates() as $date) {
            $occurrence = $this->calendar->occurrenceOn($employee, $date);
            $days[] = ['date' => $date->toDateString(), 'current_shift' => $occurrence?->shift->code, 'current_time' => $occurrence?->shift->timeLabel()];
        }

        $changes = ShiftAssignment::query()->where('employee_id', $employee->id)->overlapping($r->from_date, $r->to_date)
            ->with('shiftType')->get()->map(fn (ShiftAssignment $x) => [
                'assignment_id' => $x->id,
                'shift' => $x->shiftType->code,
                'start_date' => $x->start_date->toDateString(),
                'end_date' => $x->end_date?->toDateString(),
                'effect' => match (true) {
                    $x->start_date->lessThan($r->from_date) && ($x->end_date === null || $x->end_date->greaterThan($r->to_date)) => 'split',
                    $x->start_date->lessThan($r->from_date) => 'trimmed',
                    $x->end_date === null || $x->end_date->greaterThan($r->to_date) => 'start_moved',
                    default => 'cancelled',
                },
            ])->values()->all();

        return [
            'shift' => $shift === null ? null : ['code' => $shift->code, 'name' => $shift->name, 'time' => $shift->timeLabel(), 'colour' => $shift->colour],
            'from_date' => $r->from_date->toDateString(),
            'to_date' => $r->to_date->toDateString(),
            'reason' => $r->reason,
            'days' => $days,
            'change_set' => $changes,
            'conflicts' => $this->conflicts($r),
        ];
    }

    public function checkCompletion(ShiftRequest $r): ApprovalCheck
    {
        $conflicts = $this->conflicts($r);
        if ($conflicts['locked_dates'] !== []) {
            return ApprovalCheck::fail('attendance.approval.period_locked', ['dates' => implode(', ', $conflicts['locked_dates'])]);
        }
        if ($conflicts['processed_dates'] !== []) {
            return ApprovalCheck::fail('attendance.approval.shift_processed', ['dates' => implode(', ', $conflicts['processed_dates'])]);
        }
        if ($conflicts['other_requests'] !== []) {
            return ApprovalCheck::fail('attendance.approval.shift_request_overlap', ['numbers' => implode(', ', $conflicts['other_requests'])]);
        }

        return ApprovalCheck::ok();
    }

    public function completed(ShiftRequest $r): void
    {
        if ($r->effects_applied_at !== null) {
            return;
        }

        $employee = $r->approvalApplicant();
        $this->writer->replaceRange(
            $employee, $r->from_date, $r->to_date, ShiftType::query()->findOrFail($r->shift_type_id),
            AssignmentChangeCause::ShiftRequest, $r->id, ShiftAssignmentSource::Request, ['shift_request_id' => $r->id],
        );
        $r->forceFill(['effects_applied_at' => CarbonImmutable::now()])->save();
    }

    public function revert(ShiftRequest $r): void
    {
        if ($r->effects_applied_at === null) {
            return;
        }
        $this->writer->reverseCause($r->approvalApplicant(), AssignmentChangeCause::ShiftRequest, $r->id);
    }

    /** Whether punches were already processed against the request's assignment (cancel guard). */
    public function hasProcessedPunches(ShiftRequest $r): bool
    {
        $ids = ShiftAssignment::query()->where('shift_request_id', $r->id)->pluck('id');

        return $ids->isNotEmpty() && EmployeeCheckin::query()->whereIn('shift_assignment_id', $ids)
            ->where('processing_state', ProcessingState::Processed->value)->exists();
    }

    /**
     * @return array{locked_dates: list<string>, processed_dates: list<string>, other_requests: list<string>}
     */
    public function conflicts(ShiftRequest $r): array
    {
        $employee = $r->approvalApplicant();
        $locked = array_map(fn (CarbonImmutable $d) => $d->format('d/m/Y'), $this->locks->lockedDatesIn($employee, new DateRange($r->from_date, $r->to_date)));

        $processed = Attendance::query()->live()->where('employee_id', $employee->id)
            ->where('source', AttendanceSource::Auto->value)
            ->whereIn('attendance_status', ['present', 'half_day'])
            ->whereDate('attendance_date', '>=', $r->from_date->toDateString())
            ->whereDate('attendance_date', '<=', $r->to_date->toDateString())
            ->orderBy('attendance_date')->pluck('attendance_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->format('d/m/Y'))->unique()->values()->all();

        $others = ShiftRequest::query()->where('employee_id', $employee->id)->whereKeyNot($r->id)
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->whereDate('from_date', '<=', $r->to_date->toDateString())
            ->whereDate('to_date', '>=', $r->from_date->toDateString())
            ->pluck('number')->all();

        return ['locked_dates' => $locked, 'processed_dates' => $processed, 'other_requests' => $others];
    }
}
