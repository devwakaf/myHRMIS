<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\OtEligibilityMode;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-employee attendance settings (spec-03 §3.2), created lazily with defaults.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property bool $checkin_required
 * @property bool $geofence_exempt
 * @property bool $is_manual_labour
 * @property OtEligibilityMode $ot_eligibility
 * @property string|null $ot_eligibility_note
 * @property string|null $device_user_code
 */
class EmployeeAttendanceProfile extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by'];

    protected $attributes = [
        'checkin_required' => true,
        'geofence_exempt' => false,
        'is_manual_labour' => false,
        'ot_eligibility' => 'auto',
    ];

    protected function casts(): array
    {
        return [
            'checkin_required' => 'boolean',
            'geofence_exempt' => 'boolean',
            'is_manual_labour' => 'boolean',
            'ot_eligibility' => OtEligibilityMode::class,
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    /** Stored profile or an unsaved one with defaults (reads never create rows). */
    public static function for(Employee $employee): self
    {
        return static::acrossOrganisations()->where('employee_id', $employee->id)->first()
            ?? new self(['employee_id' => $employee->id, 'organisation_id' => $employee->organisation_id]);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
