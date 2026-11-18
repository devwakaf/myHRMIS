<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AttendanceRequestKind;
use App\Domain\Attendance\Enums\HalfDaySession;
use App\Domain\Attendance\Services\AttendanceRequestApproval;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Approval\Concerns\HasApprovalRequest;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permohonan Kehadiran — WFH / Tugas Luar (spec-03 §3.9), Approvable key
 * `attendance_request`. Effects are applied only in onApprovalCompleted (§4.8).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property AttendanceRequestKind $kind
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable $to_date
 * @property bool $is_half_day
 * @property CarbonImmutable|null $half_day_date
 * @property HalfDaySession|null $half_day_session
 * @property bool $include_rest_days
 * @property int|null $shift_type_id
 * @property string|null $duty_location
 * @property string $explanation
 * @property ApprovalStatus $status
 * @property CarbonImmutable|null $effects_applied_at
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $submitted_by
 * @property-read Employee $employee
 * @property-read Collection<int, AttendanceRequestDay> $days
 */
class AttendanceRequest extends Model implements Approvable
{
    use BelongsToOrganisation, HasApprovalRequest, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'employee_id', 'kind', 'from_date', 'to_date', 'is_half_day', 'half_day_date', 'half_day_session',
        'include_rest_days', 'shift_type_id', 'duty_location', 'explanation', 'amended_from_id',
    ];

    protected $attributes = ['status' => 'draft', 'is_half_day' => false, 'include_rest_days' => false];

    protected function casts(): array
    {
        return [
            'kind' => AttendanceRequestKind::class,
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
            'is_half_day' => 'boolean',
            'half_day_date' => 'immutable_date',
            'half_day_session' => HalfDaySession::class,
            'include_rest_days' => 'boolean',
            'employee_id' => 'integer',
            'shift_type_id' => 'integer',
            'status' => ApprovalStatus::class,
            'effects_applied_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'PKH';
    }

    public static function activityLogName(): string
    {
        return 'attendance';
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /**
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['effects_applied_at'];
    }

    public function range(): DateRange
    {
        return new DateRange($this->from_date, $this->to_date);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<AttendanceRequestDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(AttendanceRequestDay::class)->orderBy('work_date');
    }

    // ------------------------------------------------------------------ Approvable

    public function approvalType(): string
    {
        return 'attendance_request';
    }

    public function approvalApplicant(): Employee
    {
        return Employee::acrossOrganisations()->findOrFail($this->employee_id);
    }

    public function approvalAttributes(): array
    {
        return app(AttendanceRequestApproval::class)->attributes($this);
    }

    public function approvalSummary(): array
    {
        return app(AttendanceRequestApproval::class)->summary($this);
    }

    public function decisionContext(User $viewer): array
    {
        return app(AttendanceRequestApproval::class)->decisionContext($this, $viewer);
    }

    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck
    {
        return app(AttendanceRequestApproval::class)->checkCompletion($this);
    }

    public function onApprovalCompleted(ApprovalRequest $request): void
    {
        app(AttendanceRequestApproval::class)->completed($this);
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        // Nothing was applied while pending; Ditolak is terminal.
    }

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void
    {
        if ($wasApproved) {
            app(AttendanceRequestApproval::class)->revert($this);
        }
    }
}
