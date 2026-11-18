<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AssignmentChangeAction;
use App\Domain\Attendance\Enums\AssignmentChangeCause;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Enums\RestDaySource;
use App\Domain\Attendance\Enums\ShiftAssignmentSource;
use App\Domain\Attendance\Events\ShiftAssignmentsChanged;
use App\Domain\Attendance\Exceptions\AssignmentOverlap;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\EmployeeRestDay;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftAssignmentChange;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * The only code that inserts / splits / trims / cancels Penugasan Shift
 * (spec-03 §4.9). Every replaceRange step is logged in shift_assignment_changes so
 * a cause (shift request, roster edit, bulk replace, separation) can be undone exactly.
 */
final class ShiftAssignmentWriter
{
    public function __construct(
        private readonly PayrollLockQuery $locks,
        private readonly CheckinResolver $resolver,
    ) {}

    /**
     * Add an assignment; refuses overlaps (null end = +∞).
     *
     * @param  array<string, mixed>  $extra
     */
    public function add(Employee $employee, ShiftType $shift, CarbonImmutable $from, ?CarbonImmutable $to, ShiftAssignmentSource $source = ShiftAssignmentSource::Manual, array $extra = []): ShiftAssignment
    {
        if ($to !== null && $to->lessThan($from)) {
            throw new AttendanceRuleViolation('attendance.errors.range_invalid');
        }
        $this->guardLocked($employee, $from, $to);
        $this->guardOverlap($employee, $shift, $from, $to);

        $assignment = $this->insert($employee, $shift, $from, $to, $source, $extra);
        $this->changed($employee, $from, $to);

        return $assignment;
    }

    /**
     * Replace whatever covers [from, to] by $target: a shift type, 'rest' (rest days)
     * or null (clear). Overlapping assignments are cancelled / trimmed / split.
     *
     * @param  ShiftType|'rest'|null  $target
     * @param  array<string, mixed>  $extra
     * @return ShiftAssignment|null the new assignment (shift target only)
     */
    public function replaceRange(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, ShiftType|string|null $target, AssignmentChangeCause $cause, int $causeId, ShiftAssignmentSource $source = ShiftAssignmentSource::Manual, array $extra = []): ?ShiftAssignment
    {
        if ($to->lessThan($from)) {
            throw new AttendanceRuleViolation('attendance.errors.range_invalid');
        }
        $this->guardLocked($employee, $from, $to);

        $overlapping = ShiftAssignment::query()->where('employee_id', $employee->id)
            ->overlapping($from, $to)->orderBy('start_date')->get();

        foreach ($overlapping as $x) {
            $before = [
                'start_date' => $x->start_date->toDateString(),
                'end_date' => $x->end_date?->toDateString(),
                'status' => $x->status->value,
            ];
            $startsBefore = $x->start_date->lessThan($from);
            $endsAfter = $x->end_date === null || $x->end_date->greaterThan($to);

            if ($endsAfter) {
                $tail = $x->replicate(['created_by', 'updated_by']);
                $tail->forceFill(['start_date' => $to->addDay(), 'end_date' => $x->end_date, 'status' => DocumentStatus::Submitted]);
                $tail->save();
                $this->log($cause, $causeId, $tail, AssignmentChangeAction::SplitTailCreated, null);
            }

            if ($startsBefore) {
                $x->forceFill(['end_date' => $from->subDay()])->save();
                $this->log($cause, $causeId, $x, AssignmentChangeAction::Trimmed, $before);
            } else {
                $x->forceFill(['status' => DocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'cancel_reason' => $cause->value])->save();
                $this->log($cause, $causeId, $x, AssignmentChangeAction::Cancelled, $before);
            }
        }

        $restDays = EmployeeRestDay::query()->where('employee_id', $employee->id)
            ->whereDate('rest_date', '>=', $from->toDateString())->whereDate('rest_date', '<=', $to->toDateString())->get();
        foreach ($restDays as $rest) {
            if ($target === 'rest') {
                continue;
            }
            ShiftAssignmentChange::query()->create([
                'organisation_id' => $employee->organisation_id,
                'cause_type' => $cause, 'cause_id' => $causeId, 'employee_rest_day_id' => $rest->id,
                'action' => AssignmentChangeAction::RestDayRemoved,
                'before' => ['rest_date' => $rest->rest_date->toDateString(), 'source' => $rest->source->value, 'shift_schedule_assignment_id' => $rest->shift_schedule_assignment_id],
            ]);
            $rest->delete();
        }

        $created = null;
        if ($target instanceof ShiftType) {
            $created = $this->insert($employee, $target, $from, $to, $source, $extra);
            $this->log($cause, $causeId, $created, AssignmentChangeAction::Created, null);
        } elseif ($target === 'rest') {
            foreach ((new DateRange($from, $to))->dates() as $date) {
                $rest = EmployeeRestDay::query()->firstOrCreate(
                    ['employee_id' => $employee->id, 'rest_date' => $date->toDateString()],
                    ['organisation_id' => $employee->organisation_id, 'source' => RestDaySource::Manual],
                );
                if ($rest->wasRecentlyCreated) {
                    ShiftAssignmentChange::query()->create([
                        'organisation_id' => $employee->organisation_id,
                        'cause_type' => $cause, 'cause_id' => $causeId, 'employee_rest_day_id' => $rest->id,
                        'action' => AssignmentChangeAction::RestDayCreated, 'before' => null,
                    ]);
                }
            }
        }

        $this->changed($employee, $from, $to);

        return $created;
    }

    /**
     * Undo every change of a cause, newest first (e.g. cancelling an approved shift request).
     */
    public function reverseCause(Employee $employee, AssignmentChangeCause $cause, int $causeId): void
    {
        $changes = ShiftAssignmentChange::query()->where('cause_type', $cause->value)->where('cause_id', $causeId)
            ->orderByDesc('id')->get();
        $from = null;
        $to = null;

        foreach ($changes as $change) {
            $assignment = $change->shift_assignment_id === null ? null : ShiftAssignment::query()->find($change->shift_assignment_id);
            if ($assignment !== null) {
                $from = $from === null ? $assignment->start_date : $from->min($assignment->start_date);
                $to = $to === null ? $assignment->end_date : ($assignment->end_date === null ? $to : $to->max($assignment->end_date));
            }

            match ($change->action) {
                AssignmentChangeAction::Created, AssignmentChangeAction::SplitTailCreated => $assignment?->forceFill([
                    'status' => DocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'cancel_reason' => 'reversed:'.$cause->value,
                ])->save(),
                AssignmentChangeAction::Trimmed => $assignment?->forceFill(['end_date' => $change->before['end_date'] ?? null])->save(),
                AssignmentChangeAction::Cancelled => $assignment?->forceFill([
                    'status' => DocumentStatus::Submitted, 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null,
                ])->save(),
                AssignmentChangeAction::RestDayCreated => EmployeeRestDay::query()->whereKey($change->employee_rest_day_id)->delete(),
                AssignmentChangeAction::RestDayRemoved => EmployeeRestDay::query()->firstOrCreate(
                    ['employee_id' => $employee->id, 'rest_date' => $change->before['rest_date'] ?? null],
                    ['organisation_id' => $employee->organisation_id, 'source' => $change->before['source'] ?? RestDaySource::Manual->value,
                        'shift_schedule_assignment_id' => $change->before['shift_schedule_assignment_id'] ?? null],
                ),
            };
        }

        if ($from !== null) {
            $this->changed($employee, $from, $to);
        }
    }

    /**
     * Cancel an assignment: refused while processed attendance / punches reference it
     * (HR uses "Tamatkan" instead).
     */
    public function cancel(ShiftAssignment $assignment, ?User $actor, string $reason): void
    {
        if ($this->lastProcessedDate($assignment) !== null) {
            throw new AttendanceRuleViolation('attendance.errors.assignment_in_use');
        }

        $assignment->forceFill([
            'status' => DocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actor?->id, 'cancel_reason' => $reason,
        ])->save();
        $this->changed($assignment->employee, $assignment->start_date, $assignment->end_date);
    }

    /** "Tamatkan": set the end date, not before the last processed date. */
    public function end(ShiftAssignment $assignment, CarbonImmutable $endDate): void
    {
        $last = $this->lastProcessedDate($assignment);
        if ($endDate->lessThan($assignment->start_date) || ($last !== null && $endDate->lessThan($last))) {
            throw new AttendanceRuleViolation('attendance.errors.end_before_processed', ['date' => ($last ?? $assignment->start_date)->format('d/m/Y')]);
        }
        $old = $assignment->end_date;
        if ($old === null || $old->greaterThan($endDate)) {
            $this->guardLocked($assignment->employee, $endDate->addDay(), $old);
        }

        $assignment->forceFill(['end_date' => $endDate])->save();
        $this->changed($assignment->employee, $endDate->addDay(), $old);
    }

    /**
     * Cut everything after $lastDay: assignments starting later are cancelled, those
     * running past it are trimmed, later rest days removed. Used to stop a roster
     * (only that roster's rows) and on separation (all rows). Logged under $cause.
     */
    public function truncateAfter(Employee $employee, CarbonImmutable $lastDay, AssignmentChangeCause $cause, int $causeId, ?int $rosterAssignmentId = null): void
    {
        $after = $lastDay->addDay();
        $rows = ShiftAssignment::query()->where('employee_id', $employee->id)->overlapping($after, null)
            ->when($rosterAssignmentId !== null, fn ($q) => $q->where('shift_schedule_assignment_id', $rosterAssignmentId))
            ->get();

        foreach ($rows as $x) {
            $before = ['start_date' => $x->start_date->toDateString(), 'end_date' => $x->end_date?->toDateString(), 'status' => $x->status->value];
            if ($x->start_date->greaterThan($lastDay)) {
                $x->forceFill(['status' => DocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'cancel_reason' => $cause->value])->save();
                $this->log($cause, $causeId, $x, AssignmentChangeAction::Cancelled, $before);
            } else {
                $x->forceFill(['end_date' => $lastDay->toDateString()])->save();
                $this->log($cause, $causeId, $x, AssignmentChangeAction::Trimmed, $before);
            }
        }

        EmployeeRestDay::query()->where('employee_id', $employee->id)->whereDate('rest_date', '>', $lastDay->toDateString())
            ->when($rosterAssignmentId !== null, fn ($q) => $q->where('shift_schedule_assignment_id', $rosterAssignmentId))
            ->delete();

        $this->changed($employee, $after, null);
    }

    public function lastProcessedDate(ShiftAssignment $assignment): ?CarbonImmutable
    {
        $attendance = Attendance::query()->where('shift_assignment_id', $assignment->id)
            ->where('source', AttendanceSource::Auto->value)->max('attendance_date');
        $punch = EmployeeCheckin::query()->notVoided()->where('shift_assignment_id', $assignment->id)
            ->where('processing_state', ProcessingState::Processed->value)->max('shift_date');

        $dates = array_filter([$attendance, $punch]);
        if ($dates === []) {
            return null;
        }

        return CarbonImmutable::parse(max(array_map(fn ($d) => substr((string) $d, 0, 10), $dates)));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function insert(Employee $employee, ShiftType $shift, CarbonImmutable $from, ?CarbonImmutable $to, ShiftAssignmentSource $source, array $extra): ShiftAssignment
    {
        $assignment = new ShiftAssignment;
        $assignment->forceFill($extra + [
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'shift_type_id' => $shift->id,
            'start_date' => $from->toDateString(),
            'end_date' => $to?->toDateString(),
            'status' => DocumentStatus::Submitted,
            'source' => $source,
            'submitted_at' => now(),
            'submitted_by' => Auth::id(),
        ]);
        $assignment->save();

        return $assignment;
    }

    private function guardOverlap(Employee $employee, ShiftType $shift, CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        $conflicts = ShiftAssignment::query()->where('employee_id', $employee->id)->overlapping($from, $to)->with('shiftType')->get();
        if ($conflicts->isEmpty()) {
            return;
        }

        if (! AttendanceSettings::current($employee->organisation_id)->allow_multiple_assignments) {
            throw AssignmentOverlap::with($conflicts);
        }

        // Shared dates allowed only when nominal occurrences do not overlap (D−1, D, D+1).
        $probe = $from;
        $bad = [];
        foreach ($conflicts as $other) {
            foreach ([-1, 0, 1] as $offset) {
                $mine = ShiftOccurrence::build($shift, null, $probe);
                $theirs = ShiftOccurrence::build($other->shiftType, $other, $probe->addDays($offset));
                if ($mine->start->lessThan($theirs->end) && $theirs->start->lessThan($mine->end)) {
                    $bad[] = $other;
                    break;
                }
            }
        }
        if ($bad !== []) {
            throw AssignmentOverlap::with($bad);
        }
    }

    private function guardLocked(Employee $employee, CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        $locked = $this->locks->lockedDatesIn($employee, new DateRange($from, $to ?? $from->addYear()));
        if ($locked !== []) {
            throw new AttendanceRuleViolation('attendance.errors.period_locked', ['dates' => implode(', ', array_map(fn ($d) => $d->format('d/m/Y'), array_slice($locked, 0, 5)))]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function log(AssignmentChangeCause $cause, int $causeId, ShiftAssignment $assignment, AssignmentChangeAction $action, ?array $before): void
    {
        ShiftAssignmentChange::query()->create([
            'organisation_id' => $assignment->organisation_id,
            'cause_type' => $cause,
            'cause_id' => $causeId,
            'shift_assignment_id' => $assignment->id,
            'action' => $action,
            'before' => $before,
        ]);
    }

    private function changed(Employee $employee, CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        $this->resolver->reresolveEmployee($employee, $from, $to);
        ShiftAssignmentsChanged::dispatch($employee->id, $from, $to);
    }
}
