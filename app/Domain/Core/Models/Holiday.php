<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Shared\Concerns\HasLocalisedName;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use Carbon\CarbonImmutable;
use Database\Factories\Core\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated row of a holiday list (B1.13). Child of HolidayList (no organisation_id).
 *
 * @property int $id
 * @property int $holiday_list_id
 * @property CarbonImmutable $date
 * @property string $name
 * @property string|null $name_en
 * @property HolidayKind $kind
 * @property bool $is_half_day
 * @property bool $is_mandatory
 * @property int|null $source_public_holiday_id
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory, HasLocalisedName, LogsBusinessActivity;

    protected $fillable = ['holiday_list_id', 'date', 'name', 'name_en', 'kind', 'is_half_day', 'is_mandatory', 'source_public_holiday_id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'kind' => HolidayKind::class,
            'is_half_day' => 'boolean',
            'is_mandatory' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<HolidayList, $this>
     */
    public function holidayList(): BelongsTo
    {
        return $this->belongsTo(HolidayList::class);
    }
}
