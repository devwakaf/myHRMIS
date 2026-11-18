<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\TierBasis;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One leave type inside a Leave Policy (spec-02 §2.3).
 *
 * @property int $id
 * @property int $leave_policy_id
 * @property int $leave_type_id
 * @property string $annual_days
 * @property bool $uses_service_tiers
 * @property TierBasis $tier_basis
 * @property string|null $override_max_carry_forward_days
 * @property-read LeavePolicy $policy
 * @property-read LeaveType $leaveType
 * @property-read Collection<int, LeavePolicyTier> $tiers
 */
class LeavePolicyRow extends Model
{
    protected $fillable = ['leave_policy_id', 'leave_type_id', 'annual_days', 'uses_service_tiers', 'tier_basis', 'override_max_carry_forward_days'];

    protected $attributes = ['tier_basis' => 'prorata_by_anniversary', 'annual_days' => '0'];

    protected function casts(): array
    {
        return [
            'annual_days' => 'decimal:3',
            'uses_service_tiers' => 'boolean',
            'tier_basis' => TierBasis::class,
            'override_max_carry_forward_days' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<LeavePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return HasMany<LeavePolicyTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(LeavePolicyTier::class)->orderBy('min_service_months');
    }

    /** Annual days for a number of completed service months. */
    public function daysForServiceMonths(int $months): string
    {
        if (! $this->uses_service_tiers) {
            return (string) $this->annual_days;
        }

        $tier = $this->tiers->filter(fn (LeavePolicyTier $t) => $t->min_service_months <= $months)->last();

        return $tier === null ? '0' : (string) $tier->days;
    }

    /**
     * Service months at which the tier changes (ascending, excluding 0).
     *
     * @return list<int>
     */
    public function tierThresholds(): array
    {
        if (! $this->uses_service_tiers) {
            return [];
        }

        return $this->tiers->pluck('min_service_months')->map(fn ($m) => (int) $m)
            ->filter(fn (int $m) => $m > 0)->sort()->values()->all();
    }
}
