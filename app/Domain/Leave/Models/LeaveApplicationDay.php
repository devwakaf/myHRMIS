<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Leave\Enums\DayKind;
use App\Domain\Leave\Enums\DaySession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One calendar date of a Permohonan Cuti (spec-02 §2.8): source of truth for
 * attendance marking, payroll unpaid days, overlap and the calendar.
 *
 * @property int $id
 * @property int $leave_application_id
 * @property CarbonImmutable $date
 * @property DayKind $day_kind
 * @property DaySession $session
 * @property string $portion
 * @property string $unpaid_portion
 * @property int|null $holiday_list_id
 * @property int|null $leave_allocation_id
 * @property-read LeaveApplication $application
 */
class LeaveApplicationDay extends Model
{
    public $timestamps = false;

    protected $fillable = ['leave_application_id', 'date', 'day_kind', 'session', 'portion', 'unpaid_portion', 'holiday_list_id', 'leave_allocation_id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'day_kind' => DayKind::class,
            'session' => DaySession::class,
            'portion' => 'decimal:3',
            'unpaid_portion' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<LeaveApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }
}
