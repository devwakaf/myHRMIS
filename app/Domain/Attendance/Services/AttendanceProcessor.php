<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Enums\HalfDayBasis;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Enums\ReviewReason;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeEligibility;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Auto-attendance processor (spec-03 §4.4). Punches whose window closed before
 * the cut-off are grouped per (employee, shift, shift date); each group is
 * processed in its own transaction with the employee row locked, and failures
 * go to the HR review queue instead of being skipped forever.
 */
final class AttendanceProcessor
{
    public function __construct(
        private readonly DailyAttendanceCalculator $calculator,
        private readonly DayTypeResolver $dayTypes,
        private readonly AttendanceWriter $writer,
        private readonly LeaveQuery $leave,
        private readonly PayrollLockQuery $locks,
        private readonly OvertimeTypeResolver $otTypes,
    ) {}

    /**
     * Process the active organisation (call inside OrganisationContext).
     *
     * @return array{processed: int, review: int, errors: int}
     */
    public function run(?CarbonImmutable $now = null, ?int $employeeId = null): array
    {
        $now ??= CarbonImmutable::now();
        $settings = AttendanceSettings::current();
        $cutoff = $now->subMinutes($settings->sync_lag_minutes);
        $stats = ['processed' => 0, 'review' => 0, 'errors' => 0];

        // Off-shift / ambiguous punches go straight to review — nothing is dropped silently.
        $unresolved = EmployeeCheckin::query()->notVoided()
            ->where('processing_state', ProcessingState::Pending->value)
            ->where('punched_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('window_end_at')->orWhere('is_ambiguous', true))
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->get();
        foreach ($unresolved as $punch) {
            $this->review([$punch], $punch->window_end_at === null ? ReviewReason::OffShift : ReviewReason::AmbiguousShift);
            $stats['review']++;
        }

        $groups = EmployeeCheckin::query()->notVoided()
            ->join('shift_types', 'shift_types.id', '=', 'employee_checkins.shift_type_id')
            ->where('employee_checkins.processing_state', ProcessingState::Pending->value)
            ->where('employee_checkins.is_ambiguous', false)
            ->whereNotNull('employee_checkins.window_end_at')
            ->where('employee_checkins.window_end_at', '<=', $cutoff)
            ->where('shift_types.auto_attendance', true)
            ->where(fn ($q) => $q->whereNull('shift_types.process_from')
                ->orWhereColumn('employee_checkins.punched_at', '>=', 'shift_types.process_from'))
            ->when($employeeId !== null, fn ($q) => $q->where('employee_checkins.employee_id', $employeeId))
            ->select(['employee_checkins.employee_id', 'employee_checkins.shift_type_id', 'employee_checkins.shift_date'])
            ->distinct()
            ->orderBy('employee_checkins.shift_date')
            ->get();

        foreach ($groups as $group) {
            $outcome = $this->processGroup((int) $group->employee_id, (int) $group->shift_type_id, CarbonImmutable::parse($group->shift_date));
            $stats[$outcome]++;
        }

        return $stats;
    }

    /**
     * @return 'processed'|'review'|'errors'
     */
    public function processGroup(int $employeeId, int $shiftTypeId, CarbonImmutable $shiftDate, bool $replaceManual = false): string
    {
        try {
            return DB::transaction(fn () => $this->processLocked($employeeId, $shiftTypeId, $shiftDate, $replaceManual));
        } catch (Throwable $e) {
            report($e);
            $punches = $this->groupPunches($employeeId, $shiftTypeId, $shiftDate);
            $this->review($punches, ReviewReason::ProcessingError, Str::limit($e->getMessage(), 500));

            return 'errors';
        }
    }

    /**
     * @return 'processed'|'review'
     */
    private function processLocked(int $employeeId, int $shiftTypeId, CarbonImmutable $date, bool $replaceManual): string
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($employeeId);
        $punches = $this->groupPunches($employeeId, $shiftTypeId, $date);
        if ($punches === []) {
            return 'processed';
        }

        if (! EmployeeEligibility::isActiveOn($employee, $date)) {
            return $this->review($punches, ReviewReason::InactiveEmployee);
        }
        if ($this->locks->isLocked($employee, $date)) {
            return $this->review($punches, ReviewReason::LockedPeriod);
        }

        $shift = ShiftType::query()->findOrFail($shiftTypeId);
        $assignmentId = $punches[0]->shift_assignment_id;
        $assignment = $assignmentId === null ? null : ShiftAssignment::query()->find($assignmentId);
        $occurrence = ShiftOccurrence::build($shift, $assignment, $date);
        $day = $this->dayTypes->for($employee, $date, $shift);

        $existing = Attendance::query()->live()->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $date->toDateString())->get();
        $leaveDay = $this->leave->approvedLeaveOn($employee, $date);

        $fullLeave = $existing->contains(fn (Attendance $a) => $a->attendance_status === AttendanceStatus::OnLeave)
            || ($leaveDay !== null && $leaveDay->session === 'full');
        if ($fullLeave) {
            return $this->review($punches, ReviewReason::WorkedOnLeave);
        }

        $pendingHalf = $existing->first(fn (Attendance $a) => $a->attendance_status === AttendanceStatus::HalfDay
            && $a->other_half_status === OtherHalfStatus::Pending);
        if ($pendingHalf !== null) {
            $computed = $this->calculator->compute($occurrence, $punches, $day);
            $worked = (int) ($computed['worked_minutes'] ?? 0);
            $threshold = intdiv((int) $shift->absent_threshold_minutes, 2);
            $this->writer->update($pendingHalf, [
                'other_half_status' => $worked >= $threshold ? OtherHalfStatus::Present : OtherHalfStatus::Absent,
                'in_at' => $computed['in_at'] ?? $punches[0]->punched_at,
                'out_at' => $computed['out_at'] ?? null,
                'worked_minutes' => $worked,
                'punch_count' => count($punches),
            ]);
            $this->writer->linkPunches($punches, $pendingHalf);

            return 'processed';
        }

        $requestRow = $existing->first(fn (Attendance $a) => in_array($a->attendance_status, [AttendanceStatus::Wfh, AttendanceStatus::OnDuty], true)
            || ($a->attendance_status === AttendanceStatus::HalfDay && $a->half_day_basis === HalfDayBasis::Request));
        if ($requestRow !== null) {
            $this->writer->linkPunches($punches, $requestRow); // information only

            return 'processed';
        }

        $manual = $existing->first(fn (Attendance $a) => in_array($a->source, [AttendanceSource::Manual, AttendanceSource::Bulk, AttendanceSource::Import], true));
        if ($manual !== null && ! $replaceManual) {
            return $this->review($punches, ReviewReason::ExistingAttendance);
        }

        $auto = $manual ?? $existing->first(fn (Attendance $a) => $a->source === AttendanceSource::Auto
            && ($a->shift_type_id === $shiftTypeId || $a->attendance_status === AttendanceStatus::Absent));

        // Recompute with every punch of the occurrence (already linked + new).
        // Absent rows and (on "Gantikan") manual rows are superseded; other auto rows recomputed in place.
        $supersede = $auto !== null && ($auto === $manual || $auto->attendance_status === AttendanceStatus::Absent);
        $all = $punches;
        if ($auto !== null && ! $supersede) {
            $linked = EmployeeCheckin::query()->notVoided()->where('attendance_id', $auto->id)->get()->all();
            $all = array_values(array_merge($linked, $punches));
        }

        $computed = $this->calculator->compute($occurrence, $all, $day);
        if ($computed === null) {
            return $this->review($punches, ReviewReason::SinglePunch);
        }

        $computed['overtime_type_id'] = $this->otTypes->for($shift, $assignment)?->id;
        $computed['source'] = AttendanceSource::Auto;
        if ($computed['review_flag'] === null && $this->anyOutsideGeofence($all)) {
            $computed['review_flag'] = 'outside_geofence';
        }

        if ($auto !== null && $supersede) {
            $row = $this->writer->supersede($auto, $computed);
        } elseif ($auto !== null) {
            $row = $this->writer->update($auto, $computed);
        } else {
            $row = $this->writer->create($computed + [
                'organisation_id' => $employee->organisation_id,
                'employee_id' => $employee->id,
                'attendance_date' => $date->toDateString(),
            ]);
        }

        $this->writer->linkPunches($all, $row);

        return 'processed';
    }

    /**
     * @return list<EmployeeCheckin>
     */
    private function groupPunches(int $employeeId, int $shiftTypeId, CarbonImmutable $date): array
    {
        return EmployeeCheckin::query()->notVoided()
            ->where('employee_id', $employeeId)
            ->where('shift_type_id', $shiftTypeId)
            ->whereDate('shift_date', $date->toDateString())
            ->where('processing_state', ProcessingState::Pending->value)
            ->orderBy('punched_at')
            ->get()->all();
    }

    /**
     * @param  list<EmployeeCheckin>  $punches
     * @return 'review'
     */
    public function review(array $punches, ReviewReason $reason, ?string $note = null): string
    {
        foreach ($punches as $punch) {
            $punch->forceFill([
                'processing_state' => ProcessingState::NeedsReview,
                'review_reason' => $reason,
                'review_note' => $note,
            ])->save();
        }

        return 'review';
    }

    /**
     * @param  list<EmployeeCheckin>  $punches
     */
    private function anyOutsideGeofence(array $punches): bool
    {
        foreach ($punches as $punch) {
            if ($punch->geofence_result === GeofenceResult::OutsideAccepted) {
                return true;
            }
        }

        return false;
    }
}
