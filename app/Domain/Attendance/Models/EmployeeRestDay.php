<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\RestDaySource;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Hari rehat from a roster or set manually (spec-03 §3.7); wins over the holiday
 * list's weekly-off pattern while a roster governs the date (§4.2).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property CarbonImmutable $rest_date
 * @property RestDaySource $source
 * @property int|null $shift_schedule_assignment_id
 */
class EmployeeRestDay extends Model
{
    use BelongsToOrganisation;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rest_date' => 'immutable_date', 'source' => RestDaySource::class, 'employee_id' => 'integer'];
    }
}
