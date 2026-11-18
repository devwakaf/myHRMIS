<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\ScheduleDayKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slot of a Corak Roster: shift, Hari Rehat (Akta Kerja rest day) or Tiada Shift.
 *
 * @property int $id
 * @property int $shift_schedule_id
 * @property int $day_index
 * @property ScheduleDayKind $kind
 * @property int|null $shift_type_id
 * @property-read ShiftType|null $shiftType
 */
class ShiftScheduleDay extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day_index' => 'integer', 'kind' => ScheduleDayKind::class, 'shift_type_id' => 'integer'];
    }

    /**
     * @return BelongsTo<ShiftType, $this>
     */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }
}
