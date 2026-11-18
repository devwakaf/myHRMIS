<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Core\Models\Branch;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lokasi Shift — a geofence circle (spec-03 §3.8).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property int|null $branch_id
 * @property string $latitude
 * @property string $longitude
 * @property int $radius_m
 * @property int|null $accuracy_threshold_m
 * @property string|null $address_note
 * @property bool $is_active
 * @property-read Collection<int, ShiftLocationLink> $links
 */
class ShiftLocation extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by'];

    protected $attributes = ['radius_m' => 150, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_m' => 'integer',
            'accuracy_threshold_m' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /**
     * @return HasMany<ShiftLocationLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ShiftLocationLink::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
