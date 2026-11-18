<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\OtWageBaseProvider;
use App\Domain\Attendance\Enums\ClaimEligibility;
use App\Domain\Attendance\Enums\WageSource;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\OvertimeClaimLine;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Draft generation of Tuntutan OT (spec-03 §4.11 GenerateOvertimeClaims):
 * creates / refreshes one draft per employee per month, recomputing every
 * non-manual line and keeping manual lines. Attendance already on another
 * pending / approved claim is skipped (unique active line per attendance).
 */
final class OvertimeClaimGenerator
{
    public function __construct(
        private readonly OvertimeCalculator $calculator,
        private readonly OtWageBaseProvider $wages,
    ) {}

    /**
     * @return list<OvertimeClaim>
     */
    public function generateForMonth(CarbonImmutable $month, ?int $employeeId = null): array
    {
        $month = $month->startOfMonth();
        $employeeIds = Attendance::query()->live()
            ->whereNotNull('overtime_type_id')
            ->whereBetween('attendance_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->where(fn ($q) => $q->where('ot_detected_minutes', '>', 0)->orWhere(fn ($q) => $q->where('day_type', '!=', 'normal')->where('worked_minutes', '>', 0)))
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->distinct()->pluck('employee_id');

        $claims = [];
        foreach ($employeeIds as $id) {
            $employee = Employee::query()->find($id);
            if ($employee === null) {
                continue;
            }
            $claim = $this->draftFor($employee, $month);
            if ($claim !== null) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    /** Create or refresh the employee's draft for the month; null when a live non-draft claim exists. */
    public function draftFor(Employee $employee, CarbonImmutable $month): ?OvertimeClaim
    {
        $month = $month->startOfMonth();

        return DB::transaction(function () use ($employee, $month): ?OvertimeClaim {
            $existing = OvertimeClaim::query()->where('employee_id', $employee->id)
                ->whereDate('period_month', $month->toDateString())
                ->whereNotIn('status', [ApprovalStatus::Cancelled->value, ApprovalStatus::Rejected->value])
                ->lockForUpdate()->first();

            if ($existing !== null && ! $existing->isDraft()) {
                return null;
            }

            $claim = $existing ?? new OvertimeClaim;
            if (! $claim->exists) {
                $claim->forceFill([
                    'organisation_id' => $employee->organisation_id,
                    'employee_id' => $employee->id,
                    'period_month' => $month->toDateString(),
                ])->save();
            }

            return $this->refresh($claim);
        });
    }

    /** Recompute a draft claim (wage, eligibility, lines, cap, totals). */
    public function refresh(OvertimeClaim $claim): OvertimeClaim
    {
        if (! $claim->isDraft()) {
            throw new AttendanceRuleViolation('attendance.errors.not_draft');
        }

        $employee = Employee::query()->findOrFail($claim->employee_id);
        $month = $claim->period_month->startOfMonth();

        $wage = null;
        if ($claim->wage_source === WageSource::Manual && $claim->monthly_wage_snapshot !== null) {
            $wage = (string) $claim->monthly_wage_snapshot;
        } else {
            $provided = $this->wages->monthlyWageFor($employee, $month);
            $wage = $provided?->toDecimalString();
            $claim->forceFill(['monthly_wage_snapshot' => $wage, 'wage_source' => $wage === null ? null : WageSource::Payroll]);
        }
        $ctx = $this->calculator->context($employee, $month, $wage);

        $claim->lines()->where('is_manual', false)->delete();

        $taken = OvertimeClaimLine::query()->where('is_active', true)->whereNotNull('attendance_id')
            ->where('overtime_claim_id', '!=', $claim->id)->pluck('attendance_id')->all();
        $rows = Attendance::query()->live()->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->whereNotIn('id', $taken)
            ->orderBy('attendance_date')->get();

        $types = [];
        foreach ($rows as $row) {
            if (! OvertimeCalculator::qualifies($row)) {
                continue;
            }
            $type = $types[$row->overtime_type_id] ??= OvertimeType::query()->with('rules')->find($row->overtime_type_id);
            if ($type === null) {
                continue;
            }
            $claim->lines()->create($this->calculator->line($row, $type, $ctx));
        }

        return $this->totals($claim->refresh(), $ctx);
    }

    /**
     * @param  array{eligibility: ClaimEligibility, orp: string, hrp: string, hours: string, wage: string|null}  $ctx
     */
    private function totals(OvertimeClaim $claim, array $ctx): OvertimeClaim
    {
        $lines = $claim->lines()->get();
        $cap = $this->calculator->cap($claim->organisation_id, $claim->period_month, $lines->map(fn (OvertimeClaimLine $l) => [
            'beyond_normal_minutes' => $l->beyond_normal_minutes, 'counts_toward_cap' => $l->counts_toward_cap,
        ])->all());

        $total = '0';
        $minutes = 0;
        foreach ($lines as $line) {
            $total = bcadd($total, (string) $line->amount, 2);
            $minutes += $line->day_type->value === 'normal' ? $line->beyond_normal_minutes : $line->worked_minutes;
        }

        $claim->forceFill([
            'eligibility' => $ctx['eligibility'],
            'orp' => $ctx['orp'],
            'hrp' => $ctx['hrp'],
            'contractual_daily_hours' => $ctx['hours'],
            'total_ot_minutes' => $minutes,
            'cap_counted_minutes' => $cap['counted'],
            'cap_minutes' => $cap['cap'],
            'cap_exceeded' => $cap['exceeded'],
            'total_amount' => $total,
        ])->save();

        return $claim;
    }
}
