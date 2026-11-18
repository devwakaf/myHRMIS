<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan Roster (spec-03 §3.7): employee + pattern anchored at day_index 0.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property int $shift_schedule_id
 * @property CarbonImmutable $anchor_date
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $generated_through
 * @property bool $is_enabled
 * @property int|null $overtime_type_id
 * @property string|null $notes
 * @property-read ShiftSchedule $schedule
 * @property-read Employee $employee
 */
class ShiftScheduleAssignment extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by'];

    protected $attributes = ['is_enabled' => true];

    protected function casts(): array
    {
        return [
            'anchor_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'generated_through' => 'immutable_date',
            'is_enabled' => 'boolean',
            'employee_id' => 'integer',
            'overtime_type_id' => 'integer',
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /**
     * @return BelongsTo<ShiftSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ShiftSchedule::class, 'shift_schedule_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
