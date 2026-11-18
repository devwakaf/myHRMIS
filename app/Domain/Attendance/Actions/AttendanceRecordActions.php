<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Enums\ReviewReason;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Services\AbsenceMarker;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\AttendanceWriter;
use App\Domain\Attendance\Services\CheckinResolver;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR actions on Rekod Kehadiran, Semakan Kehadiran and Log Check-in
 * (spec-03 §2.11–§2.13, §4.4): Batal, Abaikan, Proses Semula, Gantikan Rekod Sedia
 * Ada, Proses Semula Tarikh Ini, Batalkan punch, Proses Sekarang.
 */
final class AttendanceRecordActions
{
    public function __construct(
        private readonly AttendanceWriter $writer,
        private readonly AttendanceProcessor $processor,
        private readonly AbsenceMarker $absences,
        private readonly CheckinResolver $resolver,
        private readonly PayrollLockQuery $locks,
    ) {}

    /** "Batal": linked punches of an auto row become `ignored` (never silently recreated). */
    public function cancel(Attendance $row, User $actor, string $reason): void
    {
        if ($row->isLeave() || $row->source === AttendanceSource::Leave) {
            throw new AttendanceRuleViolation('attendance.errors.leave_via_cuti');
        }
        if ($row->source === AttendanceSource::Request) {
            throw new AttendanceRuleViolation('attendance.errors.request_row');
        }
        $this->guardLocked($row->employee_id, $row->attendance_date);

        DB::transaction(function () use ($row, $actor, $reason): void {
            $restored = $this->writer->cancel($row, $actor, $reason);
            foreach (EmployeeCheckin::query()->where('attendance_id', $row->id)->get() as $punch) {
                $punch->forceFill([
                    'processing_state' => ProcessingState::Ignored,
                    'review_note' => __('attendance.review.cancelled_by_hr'),
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => CarbonImmutable::now(),
                    'attendance_id' => $restored?->id,
                ])->save();
            }
        });
    }

    /**
     * "Abaikan".
     *
     * @param  list<int>  $checkinIds
     */
    public function ignore(array $checkinIds, User $actor, string $note): int
    {
        $count = 0;
        foreach ($this->punches($checkinIds) as $punch) {
            $punch->forceFill([
                'processing_state' => ProcessingState::Ignored, 'review_note' => $note,
                'reviewed_by' => $actor->id, 'reviewed_at' => CarbonImmutable::now(),
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * "Proses Semula": fresh resolution, back to pending, processed immediately when the window has closed.
     *
     * @param  list<int>  $checkinIds
     */
    public function reprocess(array $checkinIds, User $actor, bool $replaceExisting = false): int
    {
        $groups = [];
        foreach ($this->punches($checkinIds) as $punch) {
            $employee = Employee::query()->findOrFail($punch->employee_id);
            $this->resolver->resolve($employee, $punch);
            $punch->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => CarbonImmutable::now()])->save();
            if ($punch->shift_type_id !== null && $punch->shift_date !== null) {
                $groups[$punch->employee_id.'|'.$punch->shift_type_id.'|'.$punch->shift_date->toDateString()] = [$punch->employee_id, $punch->shift_type_id, $punch->shift_date];
            }
        }

        foreach ($groups as [$employeeId, $shiftTypeId, $date]) {
            $this->processor->processGroup((int) $employeeId, (int) $shiftTypeId, $date, $replaceExisting);
        }

        return count($groups);
    }

    /**
     * "Gantikan Rekod Sedia Ada" (existing_attendance): supersede the manual row with the computed one.
     *
     * @param  list<int>  $checkinIds
     */
    public function replaceExisting(array $checkinIds, User $actor): int
    {
        foreach ($this->punches($checkinIds) as $punch) {
            if ($punch->review_reason !== ReviewReason::ExistingAttendance) {
                throw new AttendanceRuleViolation('attendance.errors.wrong_review_reason');
            }
        }

        return $this->reprocess($checkinIds, $actor, true);
    }

    /** "Proses Semula Tarikh Ini": every non-void punch of the date is re-resolved and reprocessed. */
    public function reprocessDate(Employee $employee, CarbonImmutable $date, User $actor): int
    {
        $this->guardLocked($employee->id, $date);
        $ids = EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('shift_date', $date->toDateString())
                ->orWhere(fn ($q) => $q->whereNull('shift_date')->whereDate('punched_at', $date->toDateString())))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $ids === [] ? 0 : $this->reprocess($ids, $actor);
    }

    public function voidCheckin(EmployeeCheckin $punch, User $actor, string $reason): void
    {
        if ($punch->voided_at !== null) {
            return;
        }
        if ($punch->processing_state === ProcessingState::Processed && $punch->attendance_id !== null) {
            $row = Attendance::query()->find($punch->attendance_id);
            if ($row !== null && $row->isLive()) {
                throw new AttendanceRuleViolation('attendance.errors.punch_processed');
            }
        }
        $punch->forceFill(['voided_at' => CarbonImmutable::now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();
    }

    /**
     * "Proses Sekarang" for the active organisation: cut-off processing, then absent marking.
     *
     * @return array<string, int>
     */
    public function processNow(): array
    {
        return $this->processor->run() + $this->absences->run();
    }

    /**
     * @param  list<int>  $ids
     * @return list<EmployeeCheckin>
     */
    private function punches(array $ids): array
    {
        return EmployeeCheckin::query()->notVoided()->whereIn('id', $ids)->orderBy('punched_at')->get()->all();
    }

    private function guardLocked(int $employeeId, CarbonImmutable $date): void
    {
        if ($this->locks->isLocked($employeeId, $date)) {
            throw new AttendanceRuleViolation('attendance.errors.period_locked', ['dates' => $date->format('d/m/Y')]);
        }
    }
}
