<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\CreditScheduleKind;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\PostedVia;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jadual Kredit Cuti (spec-02 §2.7): future accruals and service-tier uplifts.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $leave_allocation_id
 * @property CarbonImmutable $due_date
 * @property string $days
 * @property CreditScheduleKind $kind
 * @property CreditScheduleStatus $status
 * @property CarbonImmutable|null $attempted_at
 * @property string|null $failure_reason
 * @property int|null $movement_id
 * @property PostedVia|null $posted_via
 * @property-read LeaveAllocation $allocation
 */
class LeaveCreditSchedule extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'organisation_id', 'leave_allocation_id', 'due_date', 'days', 'kind', 'status', 'attempted_at',
        'failure_reason', 'movement_id', 'posted_via',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'days' => 'decimal:3',
            'kind' => CreditScheduleKind::class,
            'status' => CreditScheduleStatus::class,
            'posted_via' => PostedVia::class,
            'attempted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<LeaveAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LeaveAllocation::class, 'leave_allocation_id');
    }
}
