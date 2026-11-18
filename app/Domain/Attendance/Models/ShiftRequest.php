<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Services\ShiftRequestApproval;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permohonan Shift (spec-03 §3.6), Approvable key `shift_request`.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $shift_type_id
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable $to_date
 * @property string|null $reason
 * @property ApprovalStatus $status
 * @property CarbonImmutable|null $effects_applied_at
 * @property int|null $submitted_by
 * @property-read Employee $employee
 * @property-read ShiftType $shiftType
 */
class ShiftRequest extends Model implements Approvable
{
    use BelongsToOrganisation, HasApprovalRequest, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'employee_id', 'shift_type_id', 'from_date', 'to_date', 'reason', 'amended_from_id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
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
        return 'PSF';
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
     * @return BelongsTo<ShiftType, $this>
     */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    // ------------------------------------------------------------------ Approvable

    public function approvalType(): string
    {
        return 'shift_request';
    }

    public function approvalApplicant(): Employee
    {
        return Employee::acrossOrganisations()->findOrFail($this->employee_id);
    }

    public function approvalAttributes(): array
    {
        return app(ShiftRequestApproval::class)->attributes($this);
    }

    public function approvalSummary(): array
    {
        return app(ShiftRequestApproval::class)->summary($this);
    }

    public function decisionContext(User $viewer): array
    {
        return app(ShiftRequestApproval::class)->decisionContext($this, $viewer);
    }

    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck
    {
        return app(ShiftRequestApproval::class)->checkCompletion($this);
    }

    public function onApprovalCompleted(ApprovalRequest $request): void
    {
        app(ShiftRequestApproval::class)->completed($this);
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        // Nothing applied while pending; Ditolak is terminal.
    }

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void
    {
        if ($wasApproved) {
            app(ShiftRequestApproval::class)->revert($this);
        }
    }
}
