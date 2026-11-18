<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\AdjustmentDirection;
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
 * Pelarasan Cuti (spec-02 §2.9).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $leave_allocation_id
 * @property AdjustmentDirection $direction
 * @property string $days
 * @property CarbonImmutable $effective_date
 * @property CarbonImmutable|null $expires_on
 * @property string $reason
 * @property DocumentStatus $status
 * @property-read LeaveAllocation $allocation
 */
class LeaveAdjustment extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'leave_allocation_id', 'direction', 'days', 'effective_date', 'expires_on', 'reason', 'amended_from_id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'direction' => AdjustmentDirection::class,
            'days' => 'decimal:3',
            'effective_date' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'PLR';
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
     * @return BelongsTo<LeaveAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LeaveAllocation::class, 'leave_allocation_id');
    }
}
