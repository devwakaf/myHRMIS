<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\ShiftAssignmentSource;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan Shift (spec-03 §3.4). Created submitted (no draft); "active on D" is
 * derived from [start_date, end_date ?? +∞]. Written only by ShiftAssignmentWriter.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property int $shift_type_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property DocumentStatus $status
 * @property ShiftAssignmentSource $source
 * @property int|null $shift_request_id
 * @property int|null $shift_schedule_assignment_id
 * @property int|null $overtime_type_id
 * @property string|null $notes
 * @property-read ShiftType $shiftType
 * @property-read Employee $employee
 */
class ShiftAssignment extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by'];

    protected $attributes = ['status' => 'submitted', 'source' => 'manual'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'status' => DocumentStatus::class,
            'source' => ShiftAssignmentSource::class,
            'shift_type_id' => 'integer',
            'employee_id' => 'integer',
            'overtime_type_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    public function range(): DateRange
    {
        return new DateRange($this->start_date, $this->end_date);
    }

    /**
     * @param  Builder<ShiftAssignment>  $query
     * @return Builder<ShiftAssignment>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Submitted->value);
    }

    /**
     * Submitted assignments overlapping [from, to ?? +∞].
     *
     * @param  Builder<ShiftAssignment>  $query
     * @return Builder<ShiftAssignment>
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        $query->live()->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()));
        if ($to !== null) {
            $query->whereDate('start_date', '<=', $to->toDateString());
        }

        return $query;
    }

    /**
     * @return BelongsTo<ShiftType, $this>
     */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
