<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\EffectiveDated;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Enums\RateSetStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Header of one effective-dated statutory rate set (spec-04 §2.11). Global
 * (Super Admin). Only `unverified` and `verified` sets resolve; drafts never do.
 *
 * @property int $id
 * @property RateScheme $scheme
 * @property string $code
 * @property string|null $name
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property RateSetStatus $status
 * @property bool $is_placeholder
 * @property string|null $source_url
 * @property string|null $source_ref
 * @property string|null $notes
 * @property CarbonImmutable|null $fixture_passed_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $verified_at
 * @property int|null $verified_by
 * @property int|null $created_by
 */
class StatutoryRateSet extends Model
{
    use EffectiveDated, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft', 'is_placeholder' => false];

    protected function casts(): array
    {
        return [
            'scheme' => RateScheme::class,
            'status' => RateSetStatus::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_placeholder' => 'boolean',
            'fixture_passed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'statutory';
    }

    public function seriesKey(): array
    {
        return ['scheme' => $this->scheme->value];
    }

    public function participatesInSeries(): bool
    {
        return $this->status !== RateSetStatus::Draft;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeParticipating(Builder $query): Builder
    {
        return $query->where('status', '!=', RateSetStatus::Draft->value);
    }

    public function isVerified(): bool
    {
        return $this->status === RateSetStatus::Verified;
    }

    public function isDraft(): bool
    {
        return $this->status === RateSetStatus::Draft;
    }
}
