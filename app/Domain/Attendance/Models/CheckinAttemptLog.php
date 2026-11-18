<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AttemptResult;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Rejected check-in attempt — no coordinates kept (spec-03 §3.10, data minimisation).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property CarbonImmutable $attempted_at
 * @property AttemptResult $result
 * @property int|null $nearest_location_id
 * @property int|null $distance_m
 * @property string|null $accuracy_m
 */
class CheckinAttemptLog extends Model
{
    use BelongsToOrganisation;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'immutable_datetime',
            'result' => AttemptResult::class,
            'distance_m' => 'integer',
            'accuracy_m' => 'decimal:2',
        ];
    }
}
