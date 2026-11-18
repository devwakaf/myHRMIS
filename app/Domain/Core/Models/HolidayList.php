<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Domain\Shared\Support\Enums\MalaysianState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Core\HolidayListFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Senarai Cuti Umum (B1.13).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property string|null $name_en
 * @property CarbonImmutable $from_date
 * @property CarbonImmutable $to_date
 * @property MalaysianState|null $state
 * @property list<int>|null $weekly_off_days
 * @property list<int>|null $half_day_weekly_off_days
 * @property string|null $notes
 * @property bool $is_active
 * @property-read Collection<int, Holiday> $holidays
 */
class HolidayList extends Model
{
    /** @use HasFactory<HolidayListFactory> */
    use BelongsToOrganisation, HasFactory, HasLocalisedName, LogsBusinessActivity, TracksAuthor;

    /** Set by HolidayResolver when the list was chosen by the fallback rule (B6). Not a column. */
    public bool $resolvedByFallback = false;

    protected $fillable = [
        'organisation_id', 'name', 'name_en', 'from_date', 'to_date', 'state', 'weekly_off_days',
        'half_day_weekly_off_days', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date',
            'to_date' => 'immutable_date',
            'state' => MalaysianState::class,
            'weekly_off_days' => 'array',
            'half_day_weekly_off_days' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function range(): DateRange
    {
        return new DateRange($this->from_date, $this->to_date);
    }

    public function covers(CarbonInterface $date): bool
    {
        return $this->range()->contains($date);
    }

    /**
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->orderBy('date');
    }

    /**
     * @return HasMany<HolidayListAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(HolidayListAssignment::class);
    }
}
