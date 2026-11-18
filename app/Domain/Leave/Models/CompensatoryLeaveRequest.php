<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Services\CompensatoryLeaveApproval;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permohonan Cuti Gantian (spec-02 §2.11, §3.16) — Approvable `compensatory_leave`.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $leave_type_id
 * @property CarbonImmutable $work_from
 * @property CarbonImmutable $work_to
 * @property CarbonImmutable|null $half_day_date
 * @property string $reason
 * @property string $days_claimed
 * @property bool $attendance_unverified
 * @property list<int>|null $granted_movement_ids
 * @property ApprovalStatus $status
 * @property int|null $submitted_by
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 */
class CompensatoryLeaveRequest extends Model implements Approvable
{
    use BelongsToOrganisation, HasApprovalRequest, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'employee_id', 'leave_type_id', 'work_from', 'work_to', 'half_day_date', 'reason',
        'days_claimed', 'attendance_unverified', 'amended_from_id',
    ];

    protected $attributes = ['status' => 'draft', 'days_claimed' => '0'];

    protected function casts(): array
    {
        return [
            'work_from' => 'immutable_date',
            'work_to' => 'immutable_date',
            'half_day_date' => 'immutable_date',
            'days_claimed' => 'decimal:3',
            'attendance_unverified' => 'boolean',
            'granted_movement_ids' => 'array',
            'status' => ApprovalStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'CGT';
    }

    public static function activityLogName(): string
    {
        return 'leave';
    }

    public function activityLogModule(): string
    {
        return 'leave';
    }

    /**
     * Written by the grant hook inside the approval transaction.
     *
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['granted_movement_ids'];
    }

    public function range(): DateRange
    {
        return new DateRange($this->work_from, $this->work_to);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    // ------------------------------------------------------------------ Approvable

    public function approvalType(): string
    {
        return 'compensatory_leave';
    }

    public function approvalApplicant(): Employee
    {
        return Employee::acrossOrganisations()->findOrFail($this->employee_id);
    }

    public function approvalAttributes(): array
    {
        return app(CompensatoryLeaveApproval::class)->attributes($this);
    }

    public function approvalSummary(): array
    {
        return [
            'key' => 'leave.summary.compensatory',
            'params' => [
                'from' => $this->work_from->format('d/m/Y'),
                'to' => $this->work_to->format('d/m/Y'),
                'days' => rtrim(rtrim((string) $this->days_claimed, '0'), '.'),
            ],
        ];
    }

    public function decisionContext(User $viewer): array
    {
        return app(CompensatoryLeaveApproval::class)->decisionContext($this);
    }

    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck
    {
        return app(CompensatoryLeaveApproval::class)->checkCompletion($this);
    }

    public function onApprovalCompleted(ApprovalRequest $request): void
    {
        app(CompensatoryLeaveApproval::class)->grant($this);
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        // Nothing was granted.
    }

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void
    {
        if ($wasApproved) {
            app(CompensatoryLeaveApproval::class)->revoke($this);
        }
    }
}
