<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http;

use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\AttendanceRequestDay;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\OvertimeClaimLine;
use App\Domain\Attendance\Models\OvertimeRule;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Models\ShiftLocationLink;
use App\Domain\Attendance\Models\ShiftRequest;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftScheduleDay;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Core\Models\Employee;

/**
 * Prop shapes for the Kehadiran pages (dates Y-m-d, datetimes ISO-8601 local,
 * minutes as integers, money as decimal strings). Coordinates only when allowed.
 */
final class AttendancePresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function employee(?Employee $e): ?array
    {
        return $e === null ? null : ['id' => $e->id, 'employee_no' => $e->employee_no, 'full_name' => $e->full_name];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attendance(Attendance $a): array
    {
        return [
            'id' => $a->id,
            'employee_id' => $a->employee_id,
            'employee' => self::employee($a->relationLoaded('employee') ? $a->employee : null),
            'attendance_date' => $a->attendance_date->toDateString(),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'attendance_status' => $a->attendance_status->value,
            'attendance_status_label' => $a->attendance_status->label(),
            'half_day_basis' => $a->half_day_basis?->value,
            'half_day_session' => $a->half_day_session?->value,
            'other_half_status' => $a->other_half_status?->value,
            'day_type' => $a->day_type->value,
            'day_type_label' => $a->day_type->label(),
            'is_half_day_off' => $a->is_half_day_off,
            'holiday_label' => $a->holiday_label,
            'shift_type_id' => $a->shift_type_id,
            'shift' => $a->relationLoaded('shiftType') && $a->shiftType !== null ? ['code' => $a->shiftType->code, 'colour' => $a->shiftType->colour, 'time' => $a->shiftType->timeLabel()] : null,
            'scheduled_start_at' => $a->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $a->scheduled_end_at?->toIso8601String(),
            'in_at' => $a->in_at?->toIso8601String(),
            'out_at' => $a->out_at?->toIso8601String(),
            'punch_count' => $a->punch_count,
            'worked_minutes' => $a->worked_minutes,
            'worked_hours' => $a->worked_hours === null ? null : (string) $a->worked_hours,
            'normal_minutes' => $a->normal_minutes,
            'is_late' => $a->is_late,
            'late_minutes' => $a->late_minutes,
            'is_early_exit' => $a->is_early_exit,
            'early_exit_minutes' => $a->early_exit_minutes,
            'ot_detected_minutes' => $a->ot_detected_minutes,
            'source' => $a->source->value,
            'source_label' => $a->source->label(),
            'leave_application_id' => $a->leave_application_id,
            'attendance_request_id' => $a->attendance_request_id,
            'supersedes_id' => $a->supersedes_id,
            'superseded_by_id' => $a->superseded_by_id,
            'review_flag' => $a->review_flag,
            'remarks' => $a->remarks,
            'cancel_reason' => $a->cancel_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function checkin(EmployeeCheckin $c, bool $showCoordinates): array
    {
        return [
            'id' => $c->id,
            'employee_id' => $c->employee_id,
            'employee' => self::employee($c->relationLoaded('employee') ? $c->employee : null),
            'punched_at' => $c->punched_at->toIso8601String(),
            'received_at' => $c->received_at->toIso8601String(),
            'client_reported_at' => $c->client_reported_at?->toIso8601String(),
            'clock_skew_seconds' => $c->clock_skew_seconds,
            'skew_warning' => $c->clock_skew_seconds !== null && abs($c->clock_skew_seconds) > 300,
            'log_type' => $c->log_type?->value,
            'source' => $c->source->value,
            'source_label' => $c->source->label(),
            'geofence_result' => $c->geofence_result->value,
            'geofence_result_label' => $c->geofence_result->label(),
            'location' => $c->relationLoaded('location') && $c->location !== null ? $c->location->name : null,
            'distance_m' => $c->distance_m === null ? null : (string) $c->distance_m,
            'accuracy_m' => $c->accuracy_m === null ? null : (string) $c->accuracy_m,
            'latitude' => $showCoordinates && $c->latitude !== null ? (string) $c->latitude : null,
            'longitude' => $showCoordinates && $c->longitude !== null ? (string) $c->longitude : null,
            'shift_type_id' => $c->shift_type_id,
            'shift_date' => $c->shift_date?->toDateString(),
            'processing_state' => $c->processing_state->value,
            'processing_state_label' => $c->processing_state->label(),
            'review_reason' => $c->review_reason?->value,
            'review_reason_label' => $c->review_reason?->label(),
            'review_note' => $c->review_note,
            'attendance_id' => $c->attendance_id,
            'voided_at' => $c->voided_at?->toIso8601String(),
            'void_reason' => $c->void_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attendanceRequest(AttendanceRequest $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'employee_id' => $r->employee_id,
            'employee' => self::employee($r->relationLoaded('employee') ? $r->employee : null),
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'from_date' => $r->from_date->toDateString(),
            'to_date' => $r->to_date->toDateString(),
            'is_half_day' => $r->is_half_day,
            'half_day_date' => $r->half_day_date?->toDateString(),
            'half_day_session' => $r->half_day_session?->value,
            'include_rest_days' => $r->include_rest_days,
            'shift_type_id' => $r->shift_type_id,
            'duty_location' => $r->duty_location,
            'explanation' => $r->explanation,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'effects_applied_at' => $r->effects_applied_at?->toIso8601String(),
            'days' => $r->relationLoaded('days') ? $r->days->map(fn (AttendanceRequestDay $d) => [
                'work_date' => $d->work_date->toDateString(),
                'outcome' => $d->outcome->value,
                'outcome_label' => $d->outcome->label(),
                'attendance_id' => $d->attendance_id,
            ])->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function shiftRequest(ShiftRequest $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'employee_id' => $r->employee_id,
            'employee' => self::employee($r->relationLoaded('employee') ? $r->employee : null),
            'shift_type_id' => $r->shift_type_id,
            'shift' => $r->relationLoaded('shiftType') ? ['code' => $r->shiftType->code, 'name' => $r->shiftType->name, 'time' => $r->shiftType->timeLabel()] : null,
            'from_date' => $r->from_date->toDateString(),
            'to_date' => $r->to_date->toDateString(),
            'reason' => $r->reason,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'effects_applied_at' => $r->effects_applied_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function shiftType(ShiftType $s): array
    {
        return array_merge($s->only([
            'id', 'code', 'name', 'start_time', 'end_time', 'break_minutes', 'break_start_time', 'open_before_minutes',
            'close_after_minutes', 'late_grace_minutes', 'early_grace_minutes', 'track_late', 'track_early',
            'half_day_threshold_minutes', 'absent_threshold_minutes', 'auto_attendance', 'absent_buffer_days',
            'holiday_list_id', 'overtime_enabled', 'overtime_type_id', 'count_pre_shift_minutes', 'colour', 'normal_minutes', 'is_active',
        ]), [
            'hours_method' => $s->hours_method->value,
            'single_punch_policy' => $s->single_punch_policy->value,
            'process_from' => $s->process_from?->toDateString(),
            'is_overnight' => $s->isOvernight(),
            'time_label' => $s->timeLabel(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function location(ShiftLocation $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'branch_id' => $l->branch_id,
            'latitude' => (string) $l->latitude,
            'longitude' => (string) $l->longitude,
            'radius_m' => $l->radius_m,
            'accuracy_threshold_m' => $l->accuracy_threshold_m,
            'address_note' => $l->address_note,
            'is_active' => $l->is_active,
            'links' => $l->relationLoaded('links') ? $l->links->map(fn (ShiftLocationLink $k) => ['type' => $k->linkable_type->value, 'id' => $k->linkable_id])->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schedule(ShiftSchedule $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'cycle_length_days' => $s->cycle_length_days,
            'is_active' => $s->is_active,
            'notes' => $s->notes,
            'days' => $s->relationLoaded('days') ? $s->days->map(fn (ShiftScheduleDay $d) => [
                'day_index' => $d->day_index, 'kind' => $d->kind->value, 'shift_type_id' => $d->shift_type_id,
            ])->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assignment(ShiftAssignment $a): array
    {
        return [
            'id' => $a->id,
            'employee_id' => $a->employee_id,
            'employee' => self::employee($a->relationLoaded('employee') ? $a->employee : null),
            'shift_type_id' => $a->shift_type_id,
            'shift' => $a->relationLoaded('shiftType') ? ['code' => $a->shiftType->code, 'colour' => $a->shiftType->colour, 'time' => $a->shiftType->timeLabel()] : null,
            'start_date' => $a->start_date->toDateString(),
            'end_date' => $a->end_date?->toDateString(),
            'status' => $a->status->value,
            'source' => $a->source->value,
            'source_label' => $a->source->label(),
            'shift_request_id' => $a->shift_request_id,
            'shift_schedule_assignment_id' => $a->shift_schedule_assignment_id,
            'overtime_type_id' => $a->overtime_type_id,
            'notes' => $a->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function overtimeType(OvertimeType $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'basis' => $t->basis->value,
            'rounding_unit_minutes' => $t->rounding_unit_minutes,
            'min_minutes_per_day' => $t->min_minutes_per_day,
            'daily_max_minutes' => $t->daily_max_minutes,
            'salary_component_id' => $t->salary_component_id,
            'is_active' => $t->is_active,
            'rules' => $t->relationLoaded('rules') ? $t->rules->map(fn (OvertimeRule $r) => [
                'day_type' => $r->day_type->value,
                'beyond_normal_multiplier' => $r->beyond_normal_multiplier === null ? null : (string) $r->beyond_normal_multiplier,
                'within_normal_mode' => $r->within_normal_mode?->value,
                'within_normal_orp_days' => $r->within_normal_orp_days === null ? null : (string) $r->within_normal_orp_days,
                'salary_component_id' => $r->salary_component_id,
            ])->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function claim(OvertimeClaim $c, bool $showPay, bool $withLines = false): array
    {
        $out = [
            'id' => $c->id,
            'number' => $c->number,
            'employee_id' => $c->employee_id,
            'employee' => self::employee($c->relationLoaded('employee') ? $c->employee : null),
            'period_month' => $c->period_month->toDateString(),
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'eligibility' => $c->eligibility->value,
            'eligibility_label' => $c->eligibility->label(),
            'total_ot_minutes' => $c->total_ot_minutes,
            'total_hours' => $c->totalHours(),
            'cap_counted_minutes' => $c->cap_counted_minutes,
            'cap_minutes' => $c->cap_minutes,
            'cap_exceeded' => $c->cap_exceeded,
            'cap_override_reason' => $c->cap_override_reason,
            'posted_payroll_run_id' => $c->posted_payroll_run_id,
            'wage_source' => $c->wage_source?->value,
            'monthly_wage_snapshot' => $showPay && $c->monthly_wage_snapshot !== null ? (string) $c->monthly_wage_snapshot : null,
            'orp' => $showPay ? (string) $c->orp : null,
            'hrp' => $showPay ? (string) $c->hrp : null,
            'total_amount' => $showPay ? (string) $c->total_amount : null,
            'wage_missing' => $c->monthly_wage_snapshot === null,
        ];

        if ($withLines) {
            $out['lines'] = $c->lines->map(fn (OvertimeClaimLine $l) => [
                'id' => $l->id,
                'attendance_id' => $l->attendance_id,
                'work_date' => $l->work_date->toDateString(),
                'day_type' => $l->day_type->value,
                'day_type_label' => $l->day_type->label(),
                'overtime_type_id' => $l->overtime_type_id,
                'worked_minutes' => $l->worked_minutes,
                'normal_minutes' => $l->normal_minutes,
                'within_normal_minutes' => $l->within_normal_minutes,
                'beyond_normal_minutes' => $l->beyond_normal_minutes,
                'orp_days' => (string) $l->orp_days,
                'multiplier' => (string) $l->multiplier,
                'amount' => $showPay ? (string) $l->amount : null,
                'flags' => $l->flags ?? [],
                'note' => $l->note,
                'is_manual' => $l->is_manual,
            ])->values()->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(AttendanceSettings $s): array
    {
        return array_merge($s->only([
            'geofence_enabled', 'default_accuracy_threshold_m', 'min_punch_interval_seconds', 'location_notice_version',
            'location_retention_months', 'attempt_log_retention_days', 'allow_multiple_assignments', 'sync_lag_minutes',
            'absent_lookback_days', 'default_overtime_type_id', 'ot_generate_drafts_monthly', 'roster_horizon_days',
            'notify_roster_changes_days', 'remind_missing_checkin',
        ]), [
            'no_location_policy' => $s->no_location_policy->value,
            'outside_geofence_action' => $s->outside_geofence_action->value,
            'organisation_holiday_day_type' => $s->organisation_holiday_day_type->value,
            'ot_monthly_cap_hours' => $s->ot_monthly_cap_hours === null ? null : (string) $s->ot_monthly_cap_hours,
        ]);
    }
}
