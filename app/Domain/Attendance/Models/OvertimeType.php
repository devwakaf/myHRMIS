<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\OvertimeBasis;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis OT (spec-03 §3.12): statutory (Akta Kerja parameters) or contractual rules.
 * One rule row per day type is mandatory (validated on save by the config action).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property OvertimeBasis $basis
 * @property int $rounding_unit_minutes
 * @property int $min_minutes_per_day
 * @property int|null $daily_max_minutes
 * @property int|null $salary_component_id
 * @property bool $is_active
 * @property-read Collection<int, OvertimeRule> $rules
 */
class OvertimeType extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by'];

    protected $attributes = ['basis' => 'statutory', 'rounding_unit_minutes' => 1, 'min_minutes_per_day' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'basis' => OvertimeBasis::class,
            'rounding_unit_minutes' => 'integer',
            'min_minutes_per_day' => 'integer',
            'daily_max_minutes' => 'integer',
            'salary_component_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /**
     * @return HasMany<OvertimeRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(OvertimeRule::class);
    }

    public function ruleFor(DayType $dayType): ?OvertimeRule
    {
        return $this->rules->first(fn (OvertimeRule $r) => $r->day_type === $dayType);
    }
}
