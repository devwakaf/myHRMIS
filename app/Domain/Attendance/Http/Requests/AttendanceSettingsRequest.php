<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tetapan Kehadiran (spec-03 §3.1).
 * Authorisation is done by the controller (policies / permissions).
 */
final class AttendanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'geofence_enabled' => ['boolean'],
            'no_location_policy' => ['required', 'in:allow,deny'],
            'outside_geofence_action' => ['required', 'in:reject,accept_and_review'],
            'default_accuracy_threshold_m' => ['required', 'integer', 'min:1', 'max:5000'],
            'min_punch_interval_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'location_retention_months' => ['required', 'integer', 'min:1', 'max:120'],
            'attempt_log_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'allow_multiple_assignments' => ['boolean'],
            'sync_lag_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'absent_lookback_days' => ['required', 'integer', 'min:1', 'max:120'],
            'organisation_holiday_day_type' => ['required', 'in:normal,rest_day,public_holiday'],
            'default_overtime_type_id' => ['nullable', 'integer'],
            'ot_monthly_cap_hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
            'ot_generate_drafts_monthly' => ['boolean'],
            'roster_horizon_days' => ['required', 'integer', 'min:7', 'max:366'],
            'notify_roster_changes_days' => ['required', 'integer', 'min:0', 'max:60'],
            'remind_missing_checkin' => ['boolean'],
            'bump_notice_version' => ['nullable', 'boolean'],
        ];
    }
}
