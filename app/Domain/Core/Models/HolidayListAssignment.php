<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\AssignableType;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\EffectiveDated;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Database\Factories\Core\HolidayListAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penetapan Senarai Cuti (HLA, B1.13). Lifecycle document + effective-dated series
 * per assignable; only submitted rows take part in the series.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $holiday_list_id
 * @property AssignableType $assignable_type
 * @property int $assignable_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property DocumentStatus $status
 * @property string|null $remarks
 * @property int|null $amended_from_id
 * @property-read HolidayList $holidayList
 */
class HolidayListAssignment extends Model
{
    /** @use HasFactory<HolidayListAssignmentFactory> */
    use BelongsToOrganisation, EffectiveDated, HasDocumentNumber, HasFactory, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    protected $fillable = [
        'organisation_id', 'holiday_list_id', 'assignable_type', 'assignable_id', 'effective_from', 'remarks', 'amended_from_id',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'assignable_type' => AssignableType::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public static function documentNumberPrefix(): string
    {
        return 'HLA';
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
        return ['assignable_type' => $this->assignable_type, 'assignable_id' => $this->assignable_id];
    }

    public function participatesInSeries(): bool
    {
        return $this->status === DocumentStatus::Submitted;
    }

    /**
     * @param  Builder<HolidayListAssignment>  $query
     * @return Builder<HolidayListAssignment>
     */
    public function scopeParticipating(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Submitted->value);
    }

    /**
     * @return BelongsTo<HolidayList, $this>
     */
    public function holidayList(): BelongsTo
    {
        return $this->belongsTo(HolidayList::class);
    }

    public function assignableLabel(): string
    {
        return match ($this->assignable_type) {
            AssignableType::Organisation => (string) Organisation::query()->find($this->assignable_id)?->name,
            AssignableType::Branch => (string) Branch::query()->find($this->assignable_id)?->displayName(),
            AssignableType::Employee => (string) Employee::query()->find($this->assignable_id)?->full_name,
        };
    }
}
