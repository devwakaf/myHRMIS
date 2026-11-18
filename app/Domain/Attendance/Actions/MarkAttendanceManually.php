<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\HalfDayBasis;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Jobs\BulkMarkAttendance;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\AttendanceWriter;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeEligibility;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manual and bulk marking (spec-03 §4.10). Leave rows change only through Cuti;
 * an existing other row is superseded with the reason; open punches of the date
 * are linked as processed. Bulk: ≤ 31 days, per-row error isolation, queued above
 * QUEUE_THRESHOLD rows.
 */
final class MarkAttendanceManually
{
    public const MAX_DAYS = 31;

    public const QUEUE_THRESHOLD = 200;

    public function __construct(
        private readonly AttendanceWriter $writer,
        private readonly ShiftCalendar $calendar,
        private readonly DayTypeResolver $dayTypes,
        private readonly LeaveQuery $leave,
        private readonly PayrollLockQuery $locks,
    ) {}

    /**
     * @param  array{attendance_status: string, remarks: string, shift_type_id?: int|null, other_half_status?: string|null, half_day_session?: string|null, is_late?: bool, late_minutes?: int, is_early_exit?: bool, early_exit_minutes?: int, in_at?: string|null, out_at?: string|null}  $data
     * @return array{attendance: Attendance, superseded: bool}
     */
    public function mark(Employee $employee, CarbonImmutable $date, array $data, ?User $actor, AttendanceSource $source = AttendanceSource::Manual): array
    {
        $status = AttendanceStatus::from($data['attendance_status']);
        if ($status === AttendanceStatus::OnLeave) {
            throw new AttendanceRuleViolation('attendance.errors.leave_via_cuti');
        }
        if (trim((string) ($data['remarks'] ?? '')) === '') {
            throw ValidationException::withMessages(['remarks' => __('attendance.errors.remarks_required')]);
        }
        if ($status === AttendanceStatus::HalfDay && ! in_array($data['other_half_status'] ?? null, ['present', 'absent'], true)) {
            throw ValidationException::withMessages(['other_half_status' => __('attendance.errors.other_half_required')]);
        }

        return DB::transaction(function () use ($employee, $date, $data, $status, $source): array {
            Employee::query()->lockForUpdate()->find($employee->id);

            if (! EmployeeEligibility::isActiveOn($employee, $date)) {
                throw new AttendanceRuleViolation('attendance.errors.not_active_on', ['date' => $date->format('d/m/Y')]);
            }
            if ($this->locks->isLocked($employee, $date)) {
                throw new AttendanceRuleViolation('attendance.errors.period_locked', ['dates' => $date->format('d/m/Y')]);
            }

            $rows = Attendance::query()->live()->where('employee_id', $employee->id)->whereDate('attendance_date', $date->toDateString())->get();
            if ($rows->contains(fn (Attendance $a) => $a->isLeave()) || $this->leave->approvedLeaveOn($employee, $date) !== null) {
                throw new AttendanceRuleViolation('attendance.errors.leave_via_cuti');
            }

            $shift = isset($data['shift_type_id']) ? ShiftType::query()->findOrFail($data['shift_type_id']) : null;
            $occurrence = $shift !== null ? ShiftOccurrence::build($shift, null, $date) : $this->calendar->occurrenceOn($employee, $date);
            $info = $this->dayTypes->for($employee, $date, $occurrence?->shift);

            $attributes = [
                'organisation_id' => $employee->organisation_id,
                'employee_id' => $employee->id,
                'attendance_date' => $date->toDateString(),
                'attendance_status' => $status,
                'half_day_basis' => $status === AttendanceStatus::HalfDay ? HalfDayBasis::Worked : null,
                'other_half_status' => $status === AttendanceStatus::HalfDay ? OtherHalfStatus::from((string) $data['other_half_status']) : null,
                'half_day_session' => $status === AttendanceStatus::HalfDay ? ($data['half_day_session'] ?? null) : null,
                'day_type' => $info->dayType,
                'is_half_day_off' => $info->isHalfDayOff,
                'holiday_label' => $info->label,
                'shift_type_id' => $occurrence?->shift->id,
                'shift_assignment_id' => $occurrence?->assignment?->id,
                'scheduled_start_at' => $occurrence?->start,
                'scheduled_end_at' => $occurrence?->end,
                'normal_minutes' => $occurrence === null ? null : (int) $occurrence->shift->normal_minutes,
                'in_at' => isset($data['in_at']) ? CarbonImmutable::parse($data['in_at']) : null,
                'out_at' => isset($data['out_at']) ? CarbonImmutable::parse($data['out_at']) : null,
                'is_late' => (bool) ($data['is_late'] ?? false),
                'late_minutes' => (int) ($data['late_minutes'] ?? 0),
                'is_early_exit' => (bool) ($data['is_early_exit'] ?? false),
                'early_exit_minutes' => (int) ($data['early_exit_minutes'] ?? 0),
                'source' => $source,
                'remarks' => $data['remarks'],
            ];

            $existing = $rows->first();
            $row = $existing !== null
                ? $this->writer->supersede($existing, $attributes, $data['remarks'])
                : $this->writer->create($attributes);

            $open = EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
                ->whereIn('processing_state', [ProcessingState::Pending->value, ProcessingState::NeedsReview->value])
                ->where(fn ($q) => $q->whereDate('shift_date', $date->toDateString())
                    ->orWhere(fn ($q) => $q->whereNull('shift_date')->whereDate('punched_at', $date->toDateString())))
                ->get();
            $this->writer->linkPunches($open, $row);

            return ['attendance' => $row, 'superseded' => $existing !== null];
        });
    }

    /**
     * Tanda Kehadiran Pukal. Returns a summary, or ['queued' => token] above the threshold.
     *
     * @param  list<int>  $employeeIds
     * @param  array<string, mixed>  $data
     * @return array{created: int, superseded: int, refused: list<array{employee_id: int, date: string, reason: string}>, queued?: string}
     */
    public function bulk(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to, array $data, ?User $actor, bool $allowQueue = true): array
    {
        if ($to->lessThan($from) || (int) $from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to_date' => __('attendance.errors.range_too_long', ['days' => self::MAX_DAYS])]);
        }

        $rows = count($employeeIds) * ((int) $from->diffInDays($to) + 1);
        if ($allowQueue && $rows > self::QUEUE_THRESHOLD) {
            $token = (string) Str::uuid();
            BulkMarkAttendance::dispatch(OrganisationContext::id(), $employeeIds, $from->toDateString(), $to->toDateString(), $data, $actor?->id, $token);

            return ['created' => 0, 'superseded' => 0, 'refused' => [], 'queued' => $token];
        }

        $summary = ['created' => 0, 'superseded' => 0, 'refused' => []];
        $employees = Employee::query()->whereIn('id', $employeeIds)->get();
        foreach ($employees as $employee) {
            foreach ((new DateRange($from, $to))->dates() as $date) {
                try {
                    $result = $this->mark($employee, $date, $data, $actor, AttendanceSource::Bulk);
                    $summary[$result['superseded'] ? 'superseded' : 'created']++;
                } catch (DomainError $e) {
                    $summary['refused'][] = ['employee_id' => $employee->id, 'date' => $date->toDateString(), 'reason' => $e->userMessage()];
                } catch (ValidationException $e) {
                    $summary['refused'][] = ['employee_id' => $employee->id, 'date' => $date->toDateString(), 'reason' => implode(' ', $e->validator->errors()->all())];
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function queuedResult(string $token): ?array
    {
        $value = Cache::get('attendance-bulk:'.$token);

        return is_array($value) ? $value : null;
    }
}
