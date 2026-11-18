<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AttendanceRecordStatus;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Events\AttendanceCancelled;
use App\Domain\Attendance\Events\AttendanceMarked;
use App\Domain\Attendance\Events\AttendanceSuperseded;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * The only writer of Rekod Kehadiran status (spec-03 §3.11): create, supersede
 * (history chain), cancel (restoring what the row superseded) and in-place
 * recompute of auto rows. Every change is in the activity log via the model.
 * Callers run inside their own transaction.
 */
final class AttendanceWriter
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Attendance
    {
        $this->guardSingleRow($attributes);
        $row = new Attendance;
        $row->forceFill($attributes + ['status' => AttendanceRecordStatus::Submitted]);
        $this->deriveHours($row);
        $row->save();
        AttendanceMarked::dispatch($row);

        return $row;
    }

    /**
     * Replace $old by a new row; $old becomes Digantikan and keeps the link.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function supersede(Attendance $old, array $attributes, ?string $remarks = null): Attendance
    {
        $old->forceFill(['status' => AttendanceRecordStatus::Superseded])->save();

        $row = new Attendance;
        $row->forceFill($attributes + [
            'organisation_id' => $old->organisation_id,
            'employee_id' => $old->employee_id,
            'attendance_date' => $old->attendance_date,
            'status' => AttendanceRecordStatus::Submitted,
            'supersedes_id' => $old->id,
        ]);
        if ($remarks !== null && $row->remarks === null) {
            $row->remarks = $remarks;
        }
        $this->deriveHours($row);
        $row->save();

        $old->forceFill(['superseded_by_id' => $row->id])->save();
        AttendanceSuperseded::dispatch($old);
        AttendanceMarked::dispatch($row);

        return $row;
    }

    /**
     * Cancel a row and restore the row it superseded (if any). Returns the restored row.
     */
    public function cancel(Attendance $row, ?User $actor, string $reason): ?Attendance
    {
        if ($row->status === AttendanceRecordStatus::Cancelled) {
            return null;
        }

        $row->forceFill([
            'status' => AttendanceRecordStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancelled_by' => $actor->id ?? Auth::id(),
            'cancel_reason' => $reason,
        ])->save();
        AttendanceCancelled::dispatch($row);

        if ($row->supersedes_id === null) {
            return null;
        }

        $previous = Attendance::query()->find($row->supersedes_id);
        if ($previous === null || $previous->status !== AttendanceRecordStatus::Superseded) {
            return null;
        }

        $previous->forceFill(['status' => AttendanceRecordStatus::Submitted, 'superseded_by_id' => null])->save();
        AttendanceMarked::dispatch($previous);

        return $previous;
    }

    /**
     * Recompute an auto row in place (late punches from device / import).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Attendance $row, array $attributes): Attendance
    {
        $row->forceFill($attributes);
        $this->deriveHours($row);
        $row->save();

        return $row;
    }

    /**
     * @param  iterable<EmployeeCheckin>  $punches
     */
    public function linkPunches(iterable $punches, Attendance $row): void
    {
        foreach ($punches as $punch) {
            $punch->forceFill([
                'attendance_id' => $row->id,
                'processing_state' => ProcessingState::Processed,
                'review_reason' => null,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guardSingleRow(array $attributes): void
    {
        $employeeId = (int) $attributes['employee_id'];
        $date = CarbonImmutable::parse($attributes['attendance_date'])->toDateString();
        $orgId = $attributes['organisation_id'] ?? null;

        $settings = AttendanceSettings::current($orgId === null ? null : (int) $orgId);
        if ($settings->allow_multiple_assignments) {
            return;
        }

        $exists = Attendance::query()->live()->where('employee_id', $employeeId)->whereDate('attendance_date', $date)->exists();
        if ($exists) {
            throw new AttendanceRuleViolation('attendance.errors.duplicate_record', ['date' => CarbonImmutable::parse($date)->format('d/m/Y')]);
        }
    }

    private function deriveHours(Attendance $row): void
    {
        $row->worked_hours = $row->worked_minutes === null ? null : bcdiv((string) $row->worked_minutes, '60', 2);
    }
}
