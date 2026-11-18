<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AttendanceRecordStatus;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\HalfDayBasis;
use App\Domain\Attendance\Enums\HalfDaySession;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekod Kehadiran (spec-03 §3.11): one row per (employee, date, shift) while Sah.
 * Status changes go through AttendanceWriter (supersede chain + activity log).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property CarbonImmutable $attendance_date
 * @property AttendanceRecordStatus $status
 * @property AttendanceStatus $attendance_status
 * @property HalfDayBasis|null $half_day_basis
 * @property OtherHalfStatus|null $other_half_status
 * @property HalfDaySession|null $half_day_session
 * @property DayType $day_type
 * @property bool $is_half_day_off
 * @property string|null $holiday_label
 * @property int|null $shift_type_id
 * @property int|null $shift_assignment_id
 * @property CarbonImmutable|null $scheduled_start_at
 * @property CarbonImmutable|null $scheduled_end_at
 * @property int|null $normal_minutes
 * @property CarbonImmutable|null $in_at
 * @property CarbonImmutable|null $out_at
 * @property int $punch_count
 * @property int|null $worked_minutes
 * @property string|null $worked_hours
 * @property bool $is_late
 * @property int $late_minutes
 * @property bool $is_early_exit
 * @property int $early_exit_minutes
 * @property int $ot_detected_minutes
 * @property int|null $overtime_type_id
 * @property AttendanceSource $source
 * @property int|null $leave_application_id
 * @property int|null $leave_type_id
 * @property int|null $attendance_request_id
 * @property int|null $supersedes_id
 * @property int|null $superseded_by_id
 * @property string|null $review_flag
 * @property string|null $remarks
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property-read Employee $employee
 * @property-read ShiftType|null $shiftType
 */
class Attendance extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'active_key', 'created_by', 'updated_by'];

    protected $attributes = [
        'status' => 'submitted',
        'day_type' => 'normal',
        'is_half_day_off' => false,
        'punch_count' => 0,
        'is_late' => false,
        'late_minutes' => 0,
        'is_early_exit' => false,
        'early_exit_minutes' => 0,
        'ot_detected_minutes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'status' => AttendanceRecordStatus::class,
            'attendance_status' => AttendanceStatus::class,
            'half_day_basis' => HalfDayBasis::class,
            'other_half_status' => OtherHalfStatus::class,
            'half_day_session' => HalfDaySession::class,
            'day_type' => DayType::class,
            'is_half_day_off' => 'boolean',
            'scheduled_start_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'normal_minutes' => 'integer',
            'in_at' => 'immutable_datetime',
            'out_at' => 'immutable_datetime',
            'punch_count' => 'integer',
            'worked_minutes' => 'integer',
            'worked_hours' => 'decimal:2',
            'is_late' => 'boolean',
            'late_minutes' => 'integer',
            'is_early_exit' => 'boolean',
            'early_exit_minutes' => 'integer',
            'ot_detected_minutes' => 'integer',
            'source' => AttendanceSource::class,
            'employee_id' => 'integer',
            'shift_type_id' => 'integer',
            'shift_assignment_id' => 'integer',
            'overtime_type_id' => 'integer',
            'leave_application_id' => 'integer',
            'leave_type_id' => 'integer',
            'attendance_request_id' => 'integer',
            'supersedes_id' => 'integer',
            'superseded_by_id' => 'integer',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /**
     * @param  Builder<Attendance>  $query
     * @return Builder<Attendance>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', AttendanceRecordStatus::Submitted->value);
    }

    public function isLive(): bool
    {
        return $this->status === AttendanceRecordStatus::Submitted;
    }

    /** A row produced from punches (worked) rather than leave / request / absent. */
    public function isWorked(): bool
    {
        return $this->attendance_status === AttendanceStatus::Present
            || ($this->attendance_status === AttendanceStatus::HalfDay && $this->half_day_basis === HalfDayBasis::Worked);
    }

    public function isLeave(): bool
    {
        return $this->attendance_status === AttendanceStatus::OnLeave
            || ($this->attendance_status === AttendanceStatus::HalfDay && $this->half_day_basis === HalfDayBasis::Leave);
    }

    /** Fingerprint of the fields an OT line depends on (claim row hash, §4.11). */
    public function contentHash(): string
    {
        return sha1(implode('|', [
            $this->id, $this->status->value, $this->attendance_status->value, $this->day_type->value,
            (int) $this->worked_minutes, (int) $this->ot_detected_minutes, (int) $this->normal_minutes,
            (int) $this->overtime_type_id,
        ]));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<ShiftType, $this>
     */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }
}
