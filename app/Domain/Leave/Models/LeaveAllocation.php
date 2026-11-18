<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\AllocationSource;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peruntukan Cuti (spec-02 §2.5). Credits live in the ledger, not here.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $leave_period_id
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable $to_date
 * @property AllocationSource $source
 * @property int|null $leave_policy_assignment_id
 * @property int|null $leave_policy_row_id
 * @property string $entitlement_days
 * @property string $initial_days
 * @property CarbonImmutable|null $carry_processed_at
 * @property string|null $remarks
 * @property DocumentStatus $status
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 * @property-read LeavePeriod $period
 * @property-read LeavePolicyRow|null $policyRow
 * @property-read Collection<int, LeaveMovement> $movements
 */
class LeaveAllocation extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'employee_id', 'leave_type_id', 'leave_period_id', 'from_date', 'to_date', 'source',
        'leave_policy_assignment_id', 'leave_policy_row_id', 'entitlement_days', 'initial_days', 'remarks', 'amended_from_id',
    ];

    protected $attributes = ['status' => 'draft', 'entitlement_days' => '0', 'initial_days' => '0'];

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
            'source' => AllocationSource::class,
            'entitlement_days' => 'decimal:3',
            'initial_days' => 'decimal:3',
            'carry_processed_at' => 'immutable_datetime',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'PRC';
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
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['carry_processed_at', 'entitlement_days'];
    }

    public function range(): DateRange
    {
        return new DateRange($this->from_date, $this->to_date);
    }

    /**
     * @param  Builder<LeaveAllocation>  $query
     * @return Builder<LeaveAllocation>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Submitted->value);
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
     * @return BelongsTo<LeavePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(LeavePeriod::class, 'leave_period_id');
    }

    /**
     * @return BelongsTo<LeavePolicyRow, $this>
     */
    public function policyRow(): BelongsTo
    {
        return $this->belongsTo(LeavePolicyRow::class, 'leave_policy_row_id');
    }

    /**
     * @return BelongsTo<LeavePolicyAssignment, $this>
     */
    public function policyAssignment(): BelongsTo
    {
        return $this->belongsTo(LeavePolicyAssignment::class, 'leave_policy_assignment_id');
    }

    /**
     * @return HasMany<LeaveMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(LeaveMovement::class)->orderBy('id');
    }

    /**
     * @return HasMany<LeaveCreditSchedule, $this>
     */
    public function creditSchedules(): HasMany
    {
        return $this->hasMany(LeaveCreditSchedule::class)->orderBy('due_date');
    }
}
