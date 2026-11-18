<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\LocationLinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which branch / employee / assignment / roster assignment uses a location (spec-03 §3.8).
 *
 * @property int $id
 * @property int $shift_location_id
 * @property LocationLinkType $linkable_type
 * @property int $linkable_id
 * @property-read ShiftLocation $location
 */
class ShiftLocationLink extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['linkable_type' => LocationLinkType::class, 'linkable_id' => 'integer', 'shift_location_id' => 'integer'];
    }

    /**
     * @return BelongsTo<ShiftLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ShiftLocation::class, 'shift_location_id');
    }
}
