<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Corak Roster (spec-03 §3.7): a cycle of 1–28 day slots.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property int $cycle_length_days
 * @property bool $is_active
 * @property string|null $notes
 * @property-read Collection<int, ShiftScheduleDay> $days
 */
class ShiftSchedule extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['cycle_length_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /**
     * @return HasMany<ShiftScheduleDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(ShiftScheduleDay::class)->orderBy('day_index');
    }
}
