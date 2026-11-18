<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\CheckinSource;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Enums\LogType;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Enums\ReviewReason;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Log Check-in — one raw punch (spec-03 §3.10). The event time, source and
 * received time are immutable; coordinates / IP / user agent may only be purged
 * (set to null). Processing and void columns change freely.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property CarbonImmutable $punched_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $client_reported_at
 * @property int|null $clock_skew_seconds
 * @property string|null $client_request_id
 * @property LogType|null $log_type
 * @property CheckinSource $source
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $accuracy_m
 * @property int|null $shift_location_id
 * @property string|null $distance_m
 * @property GeofenceResult $geofence_result
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int|null $location_consent_id
 * @property int|null $shift_type_id
 * @property int|null $shift_assignment_id
 * @property CarbonImmutable|null $shift_date
 * @property bool $is_ambiguous
 * @property CarbonImmutable|null $scheduled_start_at
 * @property CarbonImmutable|null $scheduled_end_at
 * @property CarbonImmutable|null $window_start_at
 * @property CarbonImmutable|null $window_end_at
 * @property ProcessingState $processing_state
 * @property ReviewReason|null $review_reason
 * @property string|null $review_note
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $attendance_id
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property-read Employee $employee
 * @property-read ShiftLocation|null $location
 */
class EmployeeCheckin extends Model
{
    use BelongsToOrganisation;

    private const IMMUTABLE = ['employee_id', 'punched_at', 'received_at', 'client_reported_at', 'source', 'client_request_id'];

    private const PURGEABLE = ['latitude', 'longitude', 'ip_address', 'user_agent', 'accuracy_m'];

    protected $guarded = ['id'];

    protected $attributes = ['processing_state' => 'pending', 'is_ambiguous' => false];

    protected static function booted(): void
    {
        static::updating(function (self $checkin): void {
            foreach (array_keys($checkin->getDirty()) as $column) {
                if (in_array($column, self::IMMUTABLE, true)) {
                    throw new LogicException("Check-in column {$column} is immutable.");
                }
                if (in_array($column, self::PURGEABLE, true) && $checkin->getAttributes()[$column] !== null) {
                    throw new LogicException("Check-in column {$column} may only be purged.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'punched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'client_reported_at' => 'immutable_datetime',
            'clock_skew_seconds' => 'integer',
            'log_type' => LogType::class,
            'source' => CheckinSource::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_m' => 'decimal:2',
            'distance_m' => 'decimal:2',
            'geofence_result' => GeofenceResult::class,
            'employee_id' => 'integer',
            'shift_location_id' => 'integer',
            'shift_type_id' => 'integer',
            'shift_assignment_id' => 'integer',
            'shift_date' => 'immutable_date',
            'is_ambiguous' => 'boolean',
            'scheduled_start_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'window_start_at' => 'immutable_datetime',
            'window_end_at' => 'immutable_datetime',
            'processing_state' => ProcessingState::class,
            'review_reason' => ReviewReason::class,
            'reviewed_at' => 'immutable_datetime',
            'attendance_id' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<EmployeeCheckin>  $query
     * @return Builder<EmployeeCheckin>
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<ShiftLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ShiftLocation::class, 'shift_location_id');
    }
}
