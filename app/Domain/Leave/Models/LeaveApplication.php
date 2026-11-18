<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\DaySession;
use App\Domain\Leave\Services\LeaveApplicationApproval;
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
 * Permohonan Cuti (spec-02 §2.8) — Approvable under registry key `leave`.
 * All approval side effects live in the synchronous hooks (§4.1).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $leave_type_id
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable $to_date
 * @property CarbonImmutable|null $half_day_date
 * @property DaySession|null $half_day_session
 * @property CarbonImmutable|null $event_date
 * @property string|null $reason
 * @property string|null $contact_during_leave
 * @property string|null $handover_note
 * @property string $total_days
 * @property string $unpaid_days
 * @property int $calendar_days
 * @property string|null $balance_at_submit
 * @property bool $is_on_behalf
 * @property int|null $applied_by_user_id
 * @property bool $block_list_acknowledged
 * @property bool $is_backdated
 * @property ApprovalStatus $status
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $submitted_by
 * @property int|null $amended_from_id
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 * @property-read Collection<int, LeaveApplicationDay> $days
 * @property-read Collection<int, LeaveApplicationAttachment> $attachments
 */
class LeaveApplication extends Model implements Approvable
{
    use BelongsToOrganisation, HasApprovalRequest, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    public const LOCK_BYPASS_HOLIDAY = 'holiday_list_changed';

    protected $fillable = [
        'organisation_id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'half_day_date', 'half_day_session',
        'event_date', 'reason', 'contact_during_leave', 'handover_note', 'total_days', 'unpaid_days', 'calendar_days',
        'balance_at_submit', 'is_on_behalf', 'applied_by_user_id', 'block_list_acknowledged', 'is_backdated', 'amended_from_id',
    ];

    protected $attributes = ['status' => 'draft', 'total_days' => '0', 'unpaid_days' => '0', 'calendar_days' => 0];

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
            'half_day_date' => 'immutable_date',
            'half_day_session' => DaySession::class,
            'event_date' => 'immutable_date',
            'total_days' => 'decimal:3',
            'unpaid_days' => 'decimal:3',
            'balance_at_submit' => 'decimal:3',
            'is_on_behalf' => 'boolean',
            'block_list_acknowledged' => 'boolean',
            'is_backdated' => 'boolean',
            'status' => ApprovalStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'CUT';
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
     * The single A5.4 exception: holiday-list changes may rewrite computed day counts.
     *
     * @return array<string, list<string>>
     */
    public function lockBypassFields(): array
    {
        return [self::LOCK_BYPASS_HOLIDAY => ['total_days', 'unpaid_days', 'calendar_days']];
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return HasMany<LeaveApplicationDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(LeaveApplicationDay::class)->orderBy('date');
    }

    /**
     * @return HasMany<LeaveApplicationAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveApplicationAttachment::class);
    }

    // ------------------------------------------------------------------ Approvable

    public function approvalType(): string
    {
        return 'leave';
    }

    public function approvalApplicant(): Employee
    {
        return Employee::acrossOrganisations()->findOrFail($this->employee_id);
    }

    public function approvalAttributes(): array
    {
        return app(LeaveApplicationApproval::class)->attributes($this);
    }

    public function approvalSummary(): array
    {
        return app(LeaveApplicationApproval::class)->summary($this);
    }

    public function decisionContext(User $viewer): array
    {
        return app(LeaveApplicationApproval::class)->decisionContext($this, $viewer);
    }

    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck
    {
        return app(LeaveApplicationApproval::class)->checkCompletion($this, $request);
    }

    public function onApprovalCompleted(ApprovalRequest $request): void
    {
        app(LeaveApplicationApproval::class)->completed($this);
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        // Nothing to undo: no ledger or attendance rows exist; the reservation ends with `pending`.
    }

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void
    {
        if ($wasApproved) {
            app(LeaveApplicationApproval::class)->cancelledAfterApproval($this);
        }
    }
}
