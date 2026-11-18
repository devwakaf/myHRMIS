<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Leave\LeavePeriodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tempoh Cuti (spec-02 §2.2).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable $to_date
 * @property string|null $notes
 * @property CarbonImmutable|null $allocations_generated_at
 */
class LeavePeriod extends Model
{
    /** @use HasFactory<LeavePeriodFactory> */
    use BelongsToOrganisation, HasFactory, LogsBusinessActivity, TracksAuthor;

    protected $fillable = ['organisation_id', 'name', 'from_date', 'to_date', 'notes', 'allocations_generated_at'];

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
            'allocations_generated_at' => 'immutable_datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'leave';
    }

    public function range(): DateRange
    {
        return new DateRange($this->from_date, $this->to_date);
    }

    public function days(): int
    {
        return $this->range()->days();
    }

    /**
     * @param  Builder<LeavePeriod>  $query
     * @return Builder<LeavePeriod>
     */
    public function scopeContaining(Builder $query, CarbonInterface|string $date): Builder
    {
        $d = DateRange::day($date)->toDateString();

        return $query->whereDate('from_date', '<=', $d)->whereDate('to_date', '>=', $d);
    }

    public static function containing(CarbonInterface|string $date): ?self
    {
        return static::query()->containing($date)->first();
    }
}
