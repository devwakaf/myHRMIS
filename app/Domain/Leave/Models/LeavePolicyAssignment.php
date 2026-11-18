<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\EffectiveDated;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penetapan Leave Policy (spec-02 §2.4): effective-dated series per employee.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property int $leave_policy_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string|null $remarks
 * @property DocumentStatus $status
 * @property int|null $amended_from_id
 * @property-read Employee $employee
 * @property-read LeavePolicy $policy
 */
class LeavePolicyAssignment extends Model
{
    use BelongsToOrganisation, EffectiveDated, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'employee_id', 'leave_policy_id', 'effective_from', 'remarks', 'amended_from_id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'PLP';
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
        return ['effective_to'];
    }

    public function seriesKey(): array
    {
        return ['employee_id' => $this->employee_id];
    }

    public function participatesInSeries(): bool
    {
        return $this->status === DocumentStatus::Submitted;
    }

    /**
     * @param  Builder<LeavePolicyAssignment>  $query
     * @return Builder<LeavePolicyAssignment>
     */
    public function scopeParticipating(Builder $query): Builder
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
     * @return BelongsTo<LeavePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }
}
