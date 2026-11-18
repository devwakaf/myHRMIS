<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\HoursMethod;
use App\Domain\Attendance\Enums\LocationLinkType;
use App\Domain\Attendance\Enums\OtEligibilityMode;
use App\Domain\Attendance\Enums\OvertimeBasis;
use App\Domain\Attendance\Enums\ScheduleDayKind;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Models\ShiftLocationLink;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\CheckinResolver;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Support\DomainValidation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Configuration saves with their rules (spec-03 §3.1–§3.3, §3.7, §3.8, §3.12):
 * Tetapan Kehadiran, profile, Jenis Shift (+ Akta Kerja warnings), Lokasi Shift
 * (+ links), Corak Roster (+ slots), Jenis OT (+ mandatory rule per day type).
 */
final class AttendanceConfigActions
{
    public function __construct(private readonly CheckinResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveSettings(array $data, bool $bumpNotice = false): AttendanceSettings
    {
        $settings = AttendanceSettings::current();
        $settings->fill($data);
        if ($bumpNotice) {
            $settings->location_notice_version = $settings->location_notice_version + 1;
        }
        $settings->save();

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(Employee $employee, array $data): EmployeeAttendanceProfile
    {
        $profile = EmployeeAttendanceProfile::for($employee);
        $profile->fill($data);
        if ($profile->ot_eligibility !== OtEligibilityMode::Auto && trim((string) $profile->ot_eligibility_note) === '') {
            throw DomainValidation::withMessages(['ot_eligibility_note' => __('attendance.errors.eligibility_note_required')]);
        }
        $profile->save();

        return $profile;
    }

    /**
     * Save a Jenis Shift. Returns the shift plus Akta Kerja warnings; errors throw.
     *
     * @param  array<string, mixed>  $data
     * @return array{shift: ShiftType, warnings: list<string>}
     */
    public function saveShiftType(?ShiftType $shift, array $data): array
    {
        $shift ??= new ShiftType;
        $timesChanged = $shift->exists && (($data['start_time'] ?? $shift->start_time) !== $shift->start_time || ($data['end_time'] ?? $shift->end_time) !== $shift->end_time);
        $shift->fill($data);

        [$errors, $warnings] = self::checkShift($shift);
        DomainValidation::throwIf($errors);

        DB::transaction(function () use ($shift, $timesChanged): void {
            $shift->save();
            if ($timesChanged) {
                // Pending punches are re-resolved; processed attendance keeps its snapshot.
                $employeeIds = EmployeeCheckin::query()->where('shift_type_id', $shift->id)
                    ->whereIn('processing_state', ['pending', 'needs_review'])->distinct()->pluck('employee_id');
                foreach (Employee::query()->whereIn('id', $employeeIds)->get() as $employee) {
                    $from = EmployeeCheckin::query()->where('employee_id', $employee->id)->where('shift_type_id', $shift->id)
                        ->whereIn('processing_state', ['pending', 'needs_review'])->min('punched_at');
                    $this->resolver->reresolveEmployee($employee, CarbonImmutable::parse((string) $from), null);
                }
            }
        });

        return ['shift' => $shift, 'warnings' => $warnings];
    }

    /**
     * Validation (errors) and Akta Kerja warnings for a shift (spec-03 §3.3).
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function checkShift(ShiftType $shift): array
    {
        $errors = [];
        $warnings = [];
        $span = $shift->spanMinutes();
        $normal = $span - (int) $shift->break_minutes;

        if (ShiftType::minutesOf((string) $shift->start_time) === ShiftType::minutesOf((string) $shift->end_time)) {
            $errors['end_time'] = (string) __('attendance.errors.shift_same_times');
        }
        if ($span + (int) $shift->open_before_minutes + (int) $shift->close_after_minutes >= 1440) {
            $errors['close_after_minutes'] = (string) __('attendance.errors.shift_window_too_long');
        }
        if ((int) $shift->break_minutes >= $span) {
            $errors['break_minutes'] = (string) __('attendance.errors.break_too_long');
        }
        $half = (int) $shift->half_day_threshold_minutes;
        $absent = (int) $shift->absent_threshold_minutes;
        if ($half > 0 && $absent > 0 && $half < $absent) {
            $errors['half_day_threshold_minutes'] = (string) __('attendance.errors.thresholds_order');
        }
        if ($shift->hours_method === HoursMethod::Paired) {
            $errors['hours_method'] = (string) __('attendance.errors.paired_not_available');
        }
        if ($shift->auto_attendance && $shift->process_from === null) {
            $errors['process_from'] = (string) __('attendance.errors.process_from_required');
        }
        if ((int) $shift->absent_buffer_days > 7) {
            $errors['absent_buffer_days'] = (string) __('attendance.errors.buffer_too_long');
        }
        if ($normal > 720) {
            $errors['end_time'] = (string) __('attendance.errors.over_12_hours');
        }

        if ($normal > 480) {
            $warnings[] = (string) __('attendance.warnings.over_8_hours');
        }
        if ($span > 600) {
            $warnings[] = (string) __('attendance.warnings.spread_over_10_hours');
        }
        if ($shift->break_start_time !== null) {
            $start = ShiftType::minutesOf((string) $shift->start_time);
            $breakStart = ShiftType::minutesOf((string) $shift->break_start_time);
            $before = ($breakStart - $start + 1440) % 1440;
            $after = $span - $before - (int) $shift->break_minutes;
            if ($before > 300 || $after > 300 || (int) $shift->break_minutes < 30) {
                $warnings[] = (string) __('attendance.warnings.five_hour_break');
            }
        } elseif ($span > 300 && (int) $shift->break_minutes < 30) {
            $warnings[] = (string) __('attendance.warnings.five_hour_break');
        }

        return [$errors, $warnings];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{type: string, id: int}>|null  $links
     */
    public function saveLocation(?ShiftLocation $location, array $data, ?array $links = null): ShiftLocation
    {
        $lat = (float) ($data['latitude'] ?? 0);
        $lng = (float) ($data['longitude'] ?? 0);
        $errors = [];
        if ($lat === 0.0 && $lng === 0.0) {
            $errors['latitude'] = (string) __('attendance.errors.zero_coordinates');
        }
        if ($lat < -90 || $lat > 90) {
            $errors['latitude'] = (string) __('attendance.errors.latitude_range');
        }
        if ($lng < -180 || $lng > 180) {
            $errors['longitude'] = (string) __('attendance.errors.longitude_range');
        }
        $radius = (int) ($data['radius_m'] ?? 150);
        if ($radius < 20 || $radius > 5000) {
            $errors['radius_m'] = (string) __('attendance.errors.radius_range');
        }
        DomainValidation::throwIf($errors);

        return DB::transaction(function () use ($location, $data, $links): ShiftLocation {
            $location ??= new ShiftLocation;
            $location->fill($data)->save();

            if ($links !== null) {
                $location->links()->delete();
                foreach ($links as $link) {
                    ShiftLocationLink::query()->create([
                        'shift_location_id' => $location->id,
                        'linkable_type' => LocationLinkType::from($link['type']),
                        'linkable_id' => (int) $link['id'],
                    ]);
                }
            }

            return $location->load('links');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{day_index: int, kind: string, shift_type_id?: int|null}>  $days
     */
    public function saveSchedule(?ShiftSchedule $schedule, array $data, array $days): ShiftSchedule
    {
        $cycle = (int) ($data['cycle_length_days'] ?? 0);
        $errors = [];
        if ($cycle < 1 || $cycle > 28) {
            $errors['cycle_length_days'] = (string) __('attendance.errors.cycle_range');
        }
        $seen = [];
        foreach ($days as $i => $day) {
            $index = (int) $day['day_index'];
            if ($index < 0 || $index >= $cycle || isset($seen[$index])) {
                $errors["days.$i.day_index"] = (string) __('attendance.errors.slot_invalid');
            }
            $seen[$index] = true;
            if (($day['kind'] === ScheduleDayKind::Shift->value) !== ! empty($day['shift_type_id'])) {
                $errors["days.$i.shift_type_id"] = (string) __('attendance.errors.slot_shift_required');
            }
        }
        DomainValidation::throwIf($errors);

        return DB::transaction(function () use ($schedule, $data, $days): ShiftSchedule {
            $schedule ??= new ShiftSchedule;
            $schedule->fill($data)->save();
            $schedule->days()->delete();
            foreach ($days as $day) {
                $schedule->days()->create([
                    'day_index' => (int) $day['day_index'],
                    'kind' => ScheduleDayKind::from($day['kind']),
                    'shift_type_id' => $day['kind'] === ScheduleDayKind::Shift->value ? (int) $day['shift_type_id'] : null,
                ]);
            }

            return $schedule->load('days');
        });
    }

    /**
     * A rule row per day type is mandatory; statutory types keep rates null (parameters apply).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{day_type: string, beyond_normal_multiplier?: string|null, within_normal_mode?: string|null, within_normal_orp_days?: string|null, salary_component_id?: int|null}>  $rules
     */
    public function saveOvertimeType(?OvertimeType $type, array $data, array $rules): OvertimeType
    {
        $byDayType = [];
        foreach ($rules as $rule) {
            $byDayType[$rule['day_type']] = $rule;
        }
        $basis = OvertimeBasis::from((string) ($data['basis'] ?? 'statutory'));
        $errors = [];
        foreach (DayType::cases() as $dayType) {
            $rule = $byDayType[$dayType->value] ?? null;
            if ($rule === null) {
                $errors['rules'] = (string) __('attendance.errors.ot_rule_missing', ['day_type' => $dayType->label()]);

                continue;
            }
            if ($basis === OvertimeBasis::Contractual && ($rule['beyond_normal_multiplier'] ?? null) === null) {
                $errors['rules'] = (string) __('attendance.errors.ot_multiplier_required', ['day_type' => $dayType->label()]);
            }
        }
        if ((int) ($data['rounding_unit_minutes'] ?? 1) < 1) {
            $errors['rounding_unit_minutes'] = (string) __('attendance.errors.rounding_unit');
        }
        DomainValidation::throwIf($errors);

        return DB::transaction(function () use ($type, $data, $byDayType, $basis): OvertimeType {
            $type ??= new OvertimeType;
            $type->fill($data)->save();
            $type->rules()->delete();
            foreach ($byDayType as $dayType => $rule) {
                $contractual = $basis === OvertimeBasis::Contractual;
                $type->rules()->create([
                    'day_type' => DayType::from($dayType),
                    'beyond_normal_multiplier' => $contractual ? $rule['beyond_normal_multiplier'] : null,
                    'within_normal_mode' => $contractual ? ($rule['within_normal_mode'] ?? 'none') : null,
                    'within_normal_orp_days' => $contractual ? ($rule['within_normal_orp_days'] ?? null) : null,
                    'salary_component_id' => $rule['salary_component_id'] ?? null,
                ]);
            }

            return $type->load('rules');
        });
    }
}
