<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\HoursMethod;
use App\Domain\Attendance\Enums\SinglePunchPolicy;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jenis Shift (spec-03 §3.3). Times are 'H:i:s' strings; end ≤ start means overnight.
 * normal_minutes is stored on save as span − break.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property string|null $break_start_time
 * @property int $open_before_minutes
 * @property int $close_after_minutes
 * @property int $late_grace_minutes
 * @property int $early_grace_minutes
 * @property bool $track_late
 * @property bool $track_early
 * @property int $half_day_threshold_minutes
 * @property int $absent_threshold_minutes
 * @property HoursMethod $hours_method
 * @property SinglePunchPolicy $single_punch_policy
 * @property bool $auto_attendance
 * @property CarbonImmutable|null $process_from
 * @property int $absent_buffer_days
 * @property int|null $holiday_list_id
 * @property bool $overtime_enabled
 * @property int|null $overtime_type_id
 * @property bool $count_pre_shift_minutes
 * @property string $colour
 * @property int $normal_minutes
 * @property bool $is_active
 * @property-read HolidayList|null $holidayList
 */
class ShiftType extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by', 'normal_minutes'];

    protected $attributes = [
        'break_minutes' => 0,
        'open_before_minutes' => 60,
        'close_after_minutes' => 120,
        'late_grace_minutes' => 10,
        'early_grace_minutes' => 0,
        'track_late' => true,
        'track_early' => true,
        'half_day_threshold_minutes' => 240,
        'absent_threshold_minutes' => 120,
        'hours_method' => 'first_last',
        'single_punch_policy' => 'review',
        'auto_attendance' => true,
        'absent_buffer_days' => 1,
        'overtime_enabled' => false,
        'count_pre_shift_minutes' => false,
        'colour' => '#3b82f6',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $shift): void {
            $shift->setAttribute('normal_minutes', max(0, $shift->spanMinutes() - (int) $shift->break_minutes));
        });
    }

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'open_before_minutes' => 'integer',
            'close_after_minutes' => 'integer',
            'late_grace_minutes' => 'integer',
            'early_grace_minutes' => 'integer',
            'track_late' => 'boolean',
            'track_early' => 'boolean',
            'half_day_threshold_minutes' => 'integer',
            'absent_threshold_minutes' => 'integer',
            'hours_method' => HoursMethod::class,
            'single_punch_policy' => SinglePunchPolicy::class,
            'auto_attendance' => 'boolean',
            'process_from' => 'immutable_date',
            'absent_buffer_days' => 'integer',
            'overtime_enabled' => 'boolean',
            'overtime_type_id' => 'integer',
            'count_pre_shift_minutes' => 'boolean',
            'normal_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    public static function minutesOf(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return $parts[0] * 60 + ($parts[1] ?? 0);
    }

    public function isOvernight(): bool
    {
        return self::minutesOf($this->end_time) <= self::minutesOf($this->start_time);
    }

    public function spanMinutes(): int
    {
        $start = self::minutesOf((string) $this->start_time);
        $end = self::minutesOf((string) $this->end_time);

        return $end > $start ? $end - $start : $end + 1440 - $start;
    }

    /** "08:00–17:00" */
    public function timeLabel(): string
    {
        return substr((string) $this->start_time, 0, 5).'–'.substr((string) $this->end_time, 0, 5);
    }

    /**
     * @return BelongsTo<HolidayList, $this>
     */
    public function holidayList(): BelongsTo
    {
        return $this->belongsTo(HolidayList::class);
    }
}
