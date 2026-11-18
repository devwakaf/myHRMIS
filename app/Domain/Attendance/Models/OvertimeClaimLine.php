<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One day of a Tuntutan OT (spec-03 §3.12). Frozen once the claim leaves draft:
 * only `is_active` (frees the attendance for a new claim) may change afterwards.
 *
 * @property int $id
 * @property int $overtime_claim_id
 * @property int|null $attendance_id
 * @property string|null $attendance_hash
 * @property CarbonImmutable $work_date
 * @property DayType $day_type
 * @property int|null $overtime_type_id
 * @property int $worked_minutes
 * @property int $normal_minutes
 * @property int $within_normal_minutes
 * @property int $beyond_normal_minutes
 * @property string $orp_days
 * @property string $multiplier
 * @property string $amount
 * @property int|null $salary_component_id
 * @property bool $counts_toward_cap
 * @property list<string>|null $flags
 * @property string|null $note
 * @property bool $is_manual
 * @property bool $is_active
 * @property-read OvertimeClaim $claim
 */
class OvertimeClaimLine extends Model
{
    protected $guarded = ['id', 'active_attendance_key'];

    protected $attributes = ['is_active' => true, 'is_manual' => false, 'counts_toward_cap' => true];

    protected static function booted(): void
    {
        $guard = function (self $line): void {
            $claim = OvertimeClaim::query()->withoutGlobalScopes()->find($line->overtime_claim_id);
            if ($claim === null || $claim->status === ApprovalStatus::Draft) {
                return;
            }
            $dirty = array_diff(array_keys($line->getDirty()), ['is_active', 'updated_at']);
            if (! $line->exists || $dirty !== []) {
                throw new LogicException('Overtime claim lines are frozen after submit.');
            }
        };
        static::saving($guard);
        static::deleting(function (self $line): void {
            $claim = OvertimeClaim::query()->withoutGlobalScopes()->find($line->overtime_claim_id);
            if ($claim !== null && $claim->status !== ApprovalStatus::Draft) {
                throw new LogicException('Overtime claim lines are frozen after submit.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'day_type' => DayType::class,
            'attendance_id' => 'integer',
            'overtime_type_id' => 'integer',
            'worked_minutes' => 'integer',
            'normal_minutes' => 'integer',
            'within_normal_minutes' => 'integer',
            'beyond_normal_minutes' => 'integer',
            'orp_days' => 'decimal:2',
            'multiplier' => 'decimal:4',
            'amount' => 'decimal:2',
            'salary_component_id' => 'integer',
            'counts_toward_cap' => 'boolean',
            'flags' => 'array',
            'is_manual' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<OvertimeClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(OvertimeClaim::class, 'overtime_claim_id');
    }
}
