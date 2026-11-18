<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\RequestDayOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-day outcome of an approved Permohonan Kehadiran (spec-03 §3.9); drives the
 * reversal when the request is cancelled.
 *
 * @property int $id
 * @property int $attendance_request_id
 * @property CarbonImmutable $work_date
 * @property RequestDayOutcome $outcome
 * @property int|null $attendance_id
 * @property int|null $superseded_attendance_id
 */
class AttendanceRequestDay extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'outcome' => RequestDayOutcome::class,
            'attendance_id' => 'integer',
            'superseded_attendance_id' => 'integer',
        ];
    }
}
