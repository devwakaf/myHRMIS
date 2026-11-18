<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\HalfDayBasis;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Attendance\Enums\SinglePunchPolicy;
use App\Domain\Attendance\Models\AktaKerjaParameter;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Support\DayInfo;
use App\Domain\Attendance\Support\ShiftOccurrence;
use Carbon\CarbonImmutable;

/**
 * Daily computation for one occurrence, first-in / last-out (spec-03 §4.3).
 * Returns null when the single-punch policy sends the group to review.
 */
final class DailyAttendanceCalculator
{
    /** Akta Kerja: a break is due after 5 consecutive hours (DECISION §4.3 step 4). */
    public const BREAK_AFTER_MINUTES = 300;

    /**
     * @param  list<EmployeeCheckin>  $punches  non-voided, any order
     * @return array<string, mixed>|null Attendance attributes
     */
    public function compute(ShiftOccurrence $occ, array $punches, DayInfo $day): ?array
    {
        usort($punches, fn (EmployeeCheckin $a, EmployeeCheckin $b) => $a->punched_at <=> $b->punched_at);
        $shift = $occ->shift;

        $normal = (int) $shift->normal_minutes;
        $absentThreshold = (int) $shift->absent_threshold_minutes;
        $halfThreshold = (int) $shift->half_day_threshold_minutes;
        if ($day->isHalfDayOff) {
            $normal = intdiv($normal, 2);
            $absentThreshold = intdiv($absentThreshold, 2);
            $halfThreshold = intdiv($halfThreshold, 2);
        }

        $base = [
            'shift_type_id' => $shift->id,
            'shift_assignment_id' => $occ->assignment?->id,
            'scheduled_start_at' => $occ->start,
            'scheduled_end_at' => $occ->end,
            'normal_minutes' => $normal,
            'day_type' => $day->dayType,
            'is_half_day_off' => $day->isHalfDayOff,
            'holiday_label' => $day->label,
            'punch_count' => count($punches),
            'is_late' => false, 'late_minutes' => 0, 'is_early_exit' => false, 'early_exit_minutes' => 0,
            'ot_detected_minutes' => 0,
            'half_day_basis' => null,
            'other_half_status' => null,
            'half_day_session' => null,
            'review_flag' => null,
        ];

        if (count($punches) === 1) {
            $status = match ($shift->single_punch_policy) {
                SinglePunchPolicy::Review => null,
                SinglePunchPolicy::Absent => AttendanceStatus::Absent,
                SinglePunchPolicy::HalfDay => AttendanceStatus::HalfDay,
                SinglePunchPolicy::Present => AttendanceStatus::Present,
            };
            if ($status === null) {
                return null;
            }

            return array_merge($base, [
                'attendance_status' => $status,
                'in_at' => $punches[0]->punched_at,
                'out_at' => null,
                'worked_minutes' => 0,
                'half_day_basis' => $status === AttendanceStatus::HalfDay ? HalfDayBasis::Worked : null,
                'other_half_status' => $status === AttendanceStatus::HalfDay ? OtherHalfStatus::Absent : null,
            ]);
        }

        $in = $punches[0]->punched_at;
        $out = $punches[count($punches) - 1]->punched_at;
        $worked = $this->workedMinutes($occ, $in, $out);

        $result = array_merge($base, ['in_at' => $in, 'out_at' => $out, 'worked_minutes' => $worked]);

        if ($day->dayType === DayType::Normal) {
            if ($shift->track_late && $in->greaterThan($occ->start->addMinutes((int) $shift->late_grace_minutes))) {
                $result['is_late'] = true;
                $result['late_minutes'] = self::minutesBetween($occ->start, $in);
            }
            if ($shift->track_early && $out->lessThan($occ->end->subMinutes((int) $shift->early_grace_minutes))) {
                $result['is_early_exit'] = true;
                $result['early_exit_minutes'] = self::minutesBetween($out, $occ->end);
            }

            if ($absentThreshold > 0 && $worked < $absentThreshold) {
                $result['attendance_status'] = AttendanceStatus::Absent;
            } elseif ($halfThreshold > 0 && $worked < $halfThreshold) {
                $result['attendance_status'] = AttendanceStatus::HalfDay;
                $result['half_day_basis'] = HalfDayBasis::Worked;
                $result['other_half_status'] = OtherHalfStatus::Absent;
            } else {
                $result['attendance_status'] = AttendanceStatus::Present;
            }
            $result['ot_detected_minutes'] = max(0, $worked - $normal);
        } else {
            // Rest day / public holiday: any work is premium work (USER); thresholds not applied.
            $result['attendance_status'] = $worked > 0 ? AttendanceStatus::Present : AttendanceStatus::Absent;
            $result['ot_detected_minutes'] = $worked;
        }

        $maxDaily = (int) bcmul((string) AktaKerjaParameter::effectiveOn($occ->date)->max_daily_hours, '60', 0);
        if ($worked > $maxDaily) {
            $result['review_flag'] = 'over_12_hours';
        }

        return $result;
    }

    /**
     * Counted start → last punch, minus the unpaid break (§4.3 steps 3–4).
     */
    public function workedMinutes(ShiftOccurrence $occ, CarbonImmutable $in, CarbonImmutable $out): int
    {
        $shift = $occ->shift;
        $countedStart = $shift->count_pre_shift_minutes ? $in : $in->max($occ->start);
        $span = $out->greaterThan($countedStart) ? self::minutesBetween($countedStart, $out) : 0;

        $break = 0;
        if ((int) $shift->break_minutes > 0) {
            if ($shift->break_start_time !== null) {
                $breakStart = $occ->date->setTimeFromTimeString((string) $shift->break_start_time);
                if ($breakStart->lessThan($occ->start)) {
                    $breakStart = $breakStart->addDay(); // overnight shift: break after midnight
                }
                $breakEnd = $breakStart->addMinutes((int) $shift->break_minutes);
                $overlapStart = $countedStart->max($breakStart);
                $overlapEnd = $out->min($breakEnd);
                $break = $overlapEnd->greaterThan($overlapStart) ? self::minutesBetween($overlapStart, $overlapEnd) : 0;
            } elseif ($span > self::BREAK_AFTER_MINUTES) {
                $break = (int) $shift->break_minutes;
            }
        }

        return max(0, $span - $break);
    }

    public static function minutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv((int) $from->diffInSeconds($to), 60);
    }
}
