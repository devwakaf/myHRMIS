<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\EncashmentPurpose;
use App\Domain\Leave\Enums\EncashmentRateBasis;
use App\Domain\Leave\Enums\PayoutStatus;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gantian Tunai Cuti (spec-02 §2.10). Paid out by Payroll as Additional Salary.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $leave_allocation_id
 * @property CarbonImmutable $encashment_date
 * @property string $eligible_days
 * @property string $days
 * @property EncashmentRateBasis $rate_basis
 * @property string $monthly_basic
 * @property bool $basic_entered_manually
 * @property string $daily_rate
 * @property string $amount
 * @property int|null $additional_salary_id
 * @property PayoutStatus $payout_status
 * @property EncashmentPurpose $purpose
 * @property string|null $remarks
 * @property DocumentStatus $status
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 * @property-read LeaveAllocation $allocation
 */
class LeaveEncashment extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'employee_id', 'leave_type_id', 'leave_allocation_id', 'encashment_date', 'eligible_days', 'days',
        'rate_basis', 'monthly_basic', 'basic_entered_manually', 'daily_rate', 'amount', 'purpose', 'remarks', 'amended_from_id',
    ];

    protected $attributes = ['status' => 'draft', 'payout_status' => 'awaiting_payroll', 'purpose' => 'other'];

    protected function casts(): array
    {
        return [
            'encashment_date' => 'immutable_date',
            'eligible_days' => 'decimal:3',
            'days' => 'decimal:3',
            'rate_basis' => EncashmentRateBasis::class,
            'monthly_basic' => 'decimal:2',
            'basic_entered_manually' => 'boolean',
            'daily_rate' => 'decimal:6',
            'amount' => 'decimal:2',
            'payout_status' => PayoutStatus::class,
            'purpose' => EncashmentPurpose::class,
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'GTC';
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
     * Payroll writes these back after submit (§5.3).
     *
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['additional_salary_id', 'payout_status'];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['monthly_basic', 'daily_rate', 'amount'];
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
     * @return BelongsTo<LeaveAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LeaveAllocation::class, 'leave_allocation_id');
    }
}
