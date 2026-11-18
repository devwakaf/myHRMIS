<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LedgerIsImmutable;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * Ledger Cuti (spec-02 §2.6) — immutable signed ledger. A credit row is a lot;
 * every other row names the lot it acts on. Corrections are reversal rows.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int|null $leave_allocation_id
 * @property MovementKind $kind
 * @property string $days
 * @property int|null $lot_id
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property CarbonImmutable $posted_on
 * @property string $unpaid_days
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $reverses_movement_id
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read LeaveMovement|null $lot
 * @property-read LeaveMovement|null $reversed
 * @property-read LeaveAllocation|null $allocation
 */
class LeaveMovement extends Model
{
    use BelongsToOrganisation;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organisation_id', 'employee_id', 'leave_type_id', 'leave_allocation_id', 'kind', 'days', 'lot_id',
        'date_from', 'date_to', 'posted_on', 'unpaid_days', 'source_type', 'source_id', 'reverses_movement_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MovementKind::class,
            'days' => 'decimal:3',
            'unpaid_days' => 'decimal:3',
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'posted_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $movement->created_by ??= Auth::id();
        });
        static::updating(fn () => throw new LedgerIsImmutable('leave.errors.ledger_immutable'));
        static::deleting(fn () => throw new LedgerIsImmutable('leave.errors.ledger_immutable'));
    }

    public function isLot(): bool
    {
        return $this->kind->isCredit() && $this->lot_id === null && $this->reverses_movement_id === null;
    }

    /**
     * @param  Builder<LeaveMovement>  $query
     * @return Builder<LeaveMovement>
     */
    public function scopeLots(Builder $query): Builder
    {
        return $query->whereIn('kind', MovementKind::creditValues())->whereNull('lot_id')->whereNull('reverses_movement_id');
    }

    /**
     * @param  Builder<LeaveMovement>  $query
     * @return Builder<LeaveMovement>
     */
    public function scopeFromSource(Builder $query, Model $source): Builder
    {
        return $query->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey());
    }

    /**
     * @return BelongsTo<LeaveMovement, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'lot_id');
    }

    /**
     * @return BelongsTo<LeaveMovement, $this>
     */
    public function reversed(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /**
     * @return HasMany<LeaveMovement, $this>
     */
    public function lotRows(): HasMany
    {
        return $this->hasMany(self::class, 'lot_id');
    }

    /**
     * @return BelongsTo<LeaveAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LeaveAllocation::class, 'leave_allocation_id');
    }
}
