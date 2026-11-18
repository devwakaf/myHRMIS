<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\WithinNormalMode;
use Illuminate\Database\Eloquent\Model;

/**
 * Rate rule of a Jenis OT for one day type (spec-03 §3.12).
 *
 * @property int $id
 * @property int $overtime_type_id
 * @property DayType $day_type
 * @property string|null $beyond_normal_multiplier
 * @property WithinNormalMode|null $within_normal_mode
 * @property string|null $within_normal_orp_days
 * @property int|null $salary_component_id
 */
class OvertimeRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'day_type' => DayType::class,
            'beyond_normal_multiplier' => 'decimal:4',
            'within_normal_mode' => WithinNormalMode::class,
            'within_normal_orp_days' => 'decimal:2',
            'salary_component_id' => 'integer',
        ];
    }
}
