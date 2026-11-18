<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\ClaimEligibility;
use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\OtEligibilityMode;
use App\Domain\Attendance\Enums\OvertimeBasis;
use App\Domain\Attendance\Enums\WithinNormalMode;
use App\Domain\Attendance\Models\AktaKerjaParameter;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Shared\Money\Rounding;
use Carbon\CarbonImmutable;

/**
 * OT per Akta Kerja 1955 (spec-03 §4.11). ORP = W ÷ 26 (always, never the org's
 * daily-rate basis); HRP = ORP ÷ contractual daily hours. Working scale 6; only
 * line amounts are rounded (half-up, 2 dp). Pay is never truncated by the cap.
 */
final class OvertimeCalculator
{
    private const SCALE = 6;

    /**
     * Claim-level figures.
     *
     * @return array{eligibility: ClaimEligibility, orp: string, hrp: string, hours: string, wage: string|null}
     */
    public function context(Employee $employee, CarbonImmutable $month, ?string $wage): array
    {
        $params = AktaKerjaParameter::effectiveOn($month->endOfMonth());
        $profile = EmployeeAttendanceProfile::for($employee);

        $eligibility = match (true) {
            $profile->ot_eligibility === OtEligibilityMode::Never => ClaimEligibility::NotEligible,
            $profile->ot_eligibility === OtEligibilityMode::Always => ClaimEligibility::EligibleOverride,
            $profile->is_manual_labour => ClaimEligibility::EligibleManualLabour,
            $wage !== null && bccomp($wage, (string) $params->ot_wage_threshold, 2) <= 0 => ClaimEligibility::EligibleWage,
            default => ClaimEligibility::NotEligible,
        };

        $hours = $this->contractualDailyHours($employee);
        $orp = $wage === null ? '0' : bcdiv($wage, (string) $params->orp_divisor, self::SCALE);
        $hrp = bccomp($hours, '0', 2) > 0 ? bcdiv($orp, $hours, self::SCALE) : '0';

        return ['eligibility' => $eligibility, 'orp' => $orp, 'hrp' => $hrp, 'hours' => $hours, 'wage' => $wage];
    }

    public function contractualDailyHours(Employee $employee): string
    {
        if ($employee->default_shift_type_id !== null) {
            $shift = ShiftType::query()->find($employee->default_shift_type_id);
            if ($shift !== null && $shift->normal_minutes > 0) {
                return bcdiv((string) $shift->normal_minutes, '60', 2);
            }
        }

        return (string) HrSettings::forOrganisation((int) $employee->organisation_id)->standard_daily_hours;
    }

    /**
     * Minutes eligible as OT lines from an attendance row (§4.11 inputs).
     */
    public static function qualifies(Attendance $row): bool
    {
        if ($row->overtime_type_id === null) {
            return false;
        }

        return $row->day_type === DayType::Normal
            ? $row->ot_detected_minutes > 0
            : (int) $row->worked_minutes > 0;
    }

    /**
     * One claim line (§4.11 step 4).
     *
     * @param  array{eligibility: ClaimEligibility, orp: string, hrp: string}  $ctx
     * @return array<string, mixed>
     */
    public function line(Attendance $row, OvertimeType $type, array $ctx): array
    {
        $params = AktaKerjaParameter::effectiveOn($row->attendance_date);
        $type->loadMissing('rules');
        $dayType = $row->day_type;
        $worked = (int) $row->worked_minutes;
        $normal = (int) ($row->normal_minutes ?? 0);
        $eligible = $ctx['eligibility'] !== ClaimEligibility::NotEligible;
        $flags = [];

        $beyondRaw = $dayType === DayType::Normal ? (int) $row->ot_detected_minutes : max(0, $worked - $normal);
        $beyond = $this->roundMinutes($beyondRaw, $type);
        $within = $dayType === DayType::Normal ? 0 : min($worked, $normal);

        $statutory = $this->statutory($dayType, $worked, $normal, $beyond, $ctx, $params);
        $result = $statutory;

        if ($type->basis === OvertimeBasis::Contractual) {
            $contractual = $this->contractual($type, $dayType, $worked, $normal, $beyond, $ctx, $params);
            $result = ($eligible && bccomp($statutory['amount'], $contractual['amount'], 2) > 0) ? $statutory : $contractual;
        } elseif (! $eligible) {
            $result = ['orp_days' => '0', 'multiplier' => '0', 'amount' => '0.00'];
            $flags[] = 'not_eligible';
        }

        if ($type->daily_max_minutes !== null && $beyond > $type->daily_max_minutes) {
            $flags[] = 'over_daily_max';
        }
        $maxDaily = (int) bcmul((string) $params->max_daily_hours, '60', 0);
        if ($worked > $maxDaily) {
            $flags[] = 'over_12_hours';
        }

        $rule = $type->ruleFor($dayType);

        return [
            'attendance_id' => $row->id,
            'attendance_hash' => $row->contentHash(),
            'work_date' => $row->attendance_date->toDateString(),
            'day_type' => $dayType,
            'overtime_type_id' => $type->id,
            'worked_minutes' => $worked,
            'normal_minutes' => $normal,
            'within_normal_minutes' => $within,
            'beyond_normal_minutes' => $beyond,
            'orp_days' => $result['orp_days'],
            'multiplier' => $result['multiplier'],
            'amount' => $result['amount'],
            'salary_component_id' => $rule->salary_component_id ?? $type->salary_component_id,
            'counts_toward_cap' => true,
            'flags' => $flags === [] ? null : $flags,
        ];
    }

    /**
     * Monthly cap (§4.11 step 5): Σ beyond-normal minutes vs cap.
     *
     * @param  iterable<array{beyond_normal_minutes: int, counts_toward_cap: bool}>  $lines
     * @return array{counted: int, cap: int, exceeded: bool}
     */
    public function cap(int $organisationId, CarbonImmutable $month, iterable $lines): array
    {
        $counted = 0;
        foreach ($lines as $line) {
            if ($line['counts_toward_cap']) {
                $counted += (int) $line['beyond_normal_minutes'];
            }
        }
        $settingsCap = AttendanceSettings::current($organisationId)->ot_monthly_cap_hours;
        $capHours = $settingsCap ?? (string) AktaKerjaParameter::effectiveOn($month->endOfMonth())->monthly_ot_cap_hours;
        $cap = (int) bcmul((string) $capHours, '60', 0);

        return ['counted' => $counted, 'cap' => $cap, 'exceeded' => $counted > $cap];
    }

    public function roundMinutes(int $minutes, OvertimeType $type): int
    {
        $unit = max(1, $type->rounding_unit_minutes);
        $rounded = intdiv($minutes, $unit) * $unit;

        return $rounded < $type->min_minutes_per_day ? 0 : $rounded;
    }

    /**
     * @param  array{orp: string, hrp: string}  $ctx
     * @return array{orp_days: string, multiplier: string, amount: string}
     */
    private function statutory(DayType $dayType, int $worked, int $normal, int $beyond, array $ctx, AktaKerjaParameter $p): array
    {
        return match ($dayType) {
            DayType::Normal => $this->amount('0', (string) $p->normal_day_multiplier, $beyond, $ctx),
            DayType::RestDay => $this->amount($this->restDayOrpDays($worked, $normal, (string) $p->rest_day_half_orp_days, (string) $p->rest_day_full_orp_days, (string) $p->rest_day_half_ratio), (string) $p->rest_day_beyond_multiplier, $beyond, $ctx),
            DayType::PublicHoliday => $this->amount($worked > 0 ? (string) $p->ph_worked_extra_orp_days : '0', (string) $p->ph_beyond_multiplier, $beyond, $ctx),
        };
    }

    /**
     * @param  array{orp: string, hrp: string}  $ctx
     * @return array{orp_days: string, multiplier: string, amount: string}
     */
    private function contractual(OvertimeType $type, DayType $dayType, int $worked, int $normal, int $beyond, array $ctx, AktaKerjaParameter $p): array
    {
        $rule = $type->ruleFor($dayType);
        $multiplier = (string) ($rule->beyond_normal_multiplier ?? '0');
        $orpDays = match ($dayType === DayType::Normal ? WithinNormalMode::None : ($rule->within_normal_mode ?? WithinNormalMode::None)) {
            WithinNormalMode::None => '0',
            WithinNormalMode::OrpHalfOrFull => $this->restDayOrpDays($worked, $normal, (string) $p->rest_day_half_orp_days, (string) $p->rest_day_full_orp_days, (string) $p->rest_day_half_ratio),
            WithinNormalMode::OrpDays => $worked > 0 ? (string) ($rule->within_normal_orp_days ?? '0') : '0',
        };

        return $this->amount($orpDays, $multiplier, $beyond, $ctx);
    }

    private function restDayOrpDays(int $worked, int $normal, string $half, string $full, string $ratio): string
    {
        if ($worked <= 0) {
            return '0';
        }
        $within = min($worked, $normal);

        return bccomp((string) $within, bcmul((string) $normal, $ratio, 4), 4) <= 0 ? $half : $full;
    }

    /**
     * amount = orp_days × ORP + beyond/60 × HRP × multiplier, rounded half-up to sen.
     *
     * @param  array{orp: string, hrp: string}  $ctx
     * @return array{orp_days: string, multiplier: string, amount: string}
     */
    private function amount(string $orpDays, string $multiplier, int $beyond, array $ctx): array
    {
        $within = bcmul($orpDays, $ctx['orp'], self::SCALE);
        $beyondPay = bcdiv(bcmul(bcmul($ctx['hrp'], $multiplier, self::SCALE), (string) $beyond, self::SCALE), '60', self::SCALE);

        return [
            'orp_days' => $orpDays,
            'multiplier' => $multiplier,
            'amount' => (string) Rounding::halfUp(bcadd($within, $beyondPay, self::SCALE), 2),
        ];
    }
}
