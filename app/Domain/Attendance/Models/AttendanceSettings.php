<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\NoLocationPolicy;
use App\Domain\Attendance\Enums\OutsideGeofenceAction;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Tetapan Kehadiran (spec-03 §3.1) — one row per Organisasi, created on first use.
 *
 * @property int $id
 * @property int $organisation_id
 * @property bool $geofence_enabled
 * @property NoLocationPolicy $no_location_policy
 * @property OutsideGeofenceAction $outside_geofence_action
 * @property int $default_accuracy_threshold_m
 * @property int $min_punch_interval_seconds
 * @property int $location_notice_version
 * @property int $location_retention_months
 * @property int $attempt_log_retention_days
 * @property bool $allow_multiple_assignments
 * @property int $sync_lag_minutes
 * @property int $absent_lookback_days
 * @property DayType $organisation_holiday_day_type
 * @property int|null $default_overtime_type_id
 * @property string|null $ot_monthly_cap_hours
 * @property bool $ot_generate_drafts_monthly
 * @property int $roster_horizon_days
 * @property int $notify_roster_changes_days
 * @property bool $remind_missing_checkin
 */
class AttendanceSettings extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $table = 'attendance_settings';

    protected $guarded = ['id', 'created_by', 'updated_by'];

    protected $attributes = [
        'geofence_enabled' => true,
        'no_location_policy' => 'allow',
        'outside_geofence_action' => 'reject',
        'default_accuracy_threshold_m' => 100,
        'min_punch_interval_seconds' => 60,
        'location_notice_version' => 1,
        'location_retention_months' => 12,
        'attempt_log_retention_days' => 90,
        'allow_multiple_assignments' => false,
        'sync_lag_minutes' => 0,
        'absent_lookback_days' => 35,
        'organisation_holiday_day_type' => 'public_holiday',
        'ot_generate_drafts_monthly' => true,
        'roster_horizon_days' => 60,
        'notify_roster_changes_days' => 7,
        'remind_missing_checkin' => false,
    ];

    protected function casts(): array
    {
        return [
            'geofence_enabled' => 'boolean',
            'no_location_policy' => NoLocationPolicy::class,
            'outside_geofence_action' => OutsideGeofenceAction::class,
            'default_accuracy_threshold_m' => 'integer',
            'min_punch_interval_seconds' => 'integer',
            'location_notice_version' => 'integer',
            'location_retention_months' => 'integer',
            'attempt_log_retention_days' => 'integer',
            'allow_multiple_assignments' => 'boolean',
            'sync_lag_minutes' => 'integer',
            'absent_lookback_days' => 'integer',
            'organisation_holiday_day_type' => DayType::class,
            'default_overtime_type_id' => 'integer',
            'ot_monthly_cap_hours' => 'decimal:2',
            'ot_generate_drafts_monthly' => 'boolean',
            'roster_horizon_days' => 'integer',
            'notify_roster_changes_days' => 'integer',
            'remind_missing_checkin' => 'boolean',
        ];
    }

    public function activityLogModule(): string
    {
        return 'attendance';
    }

    public static function current(?int $organisationId = null): self
    {
        $orgId = $organisationId ?? OrganisationContext::id();

        return static::acrossOrganisations()->firstOrCreate(['organisation_id' => $orgId]);
    }
}
