<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\OvertimeClaimApproved;
use App\Domain\Attendance\Events\OvertimeClaimCancelled;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\OvertimeClaimLine;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The `overtime_claim` Approvable hooks (spec-03 §4.8, §4.11, §4.12).
 * Amounts are shown only to viewers holding core.employee.view-compensation.
 */
final class OvertimeClaimApproval
{
    /**
     * @return array<string, scalar|null>
     */
    public function attributes(OvertimeClaim $claim): array
    {
        return [
            'overtime_claim.hours' => $claim->totalHours(),
            'overtime_claim.amount' => (string) $claim->total_amount,
            'overtime_claim.needs_compliance_review' => self::needsComplianceReview($claim),
            'overtime_claim.eligibility' => $claim->eligibility->value,
        ];
    }

    public static function needsComplianceReview(OvertimeClaim $claim): bool
    {
        if ($claim->cap_exceeded) {
            return true;
        }

        return $claim->lines()->get()->contains(fn (OvertimeClaimLine $l) => in_array('over_12_hours', $l->flags ?? [], true));
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}
     */
    public function summary(OvertimeClaim $claim): array
    {
        return [
            'key' => 'attendance.summary.overtime_claim',
            'params' => ['month' => $claim->period_month->format('m/Y'), 'hours' => $claim->totalHours()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decisionContext(OvertimeClaim $claim, User $viewer): array
    {
        $showPay = $viewer->can('core.employee.view-compensation');
        $lines = $claim->lines()->get();
        $byDayType = [];
        foreach ($lines as $line) {
            $key = $line->day_type->value;
            $byDayType[$key] ??= ['minutes' => 0, 'days' => 0];
            $byDayType[$key]['minutes'] += $line->day_type->value === 'normal' ? $line->beyond_normal_minutes : $line->worked_minutes;
            $byDayType[$key]['days']++;
        }

        return [
            'number' => $claim->number,
            'period_month' => $claim->period_month->toDateString(),
            'eligibility' => $claim->eligibility->value,
            'eligibility_label' => $claim->eligibility->label(),
            'total_hours' => $claim->totalHours(),
            'by_day_type' => $byDayType,
            'cap' => [
                'counted_minutes' => $claim->cap_counted_minutes,
                'cap_minutes' => $claim->cap_minutes,
                'exceeded' => $claim->cap_exceeded,
                'override_reason' => $claim->cap_override_reason,
            ],
            'show_amounts' => $showPay,
            'wage' => $showPay ? ['monthly' => $claim->monthly_wage_snapshot, 'orp' => (string) $claim->orp, 'hrp' => (string) $claim->hrp] : null,
            'total_amount' => $showPay ? (string) $claim->total_amount : null,
            'lines' => $lines->map(fn (OvertimeClaimLine $l) => [
                'work_date' => $l->work_date->toDateString(),
                'day_type' => $l->day_type->value,
                'worked_minutes' => $l->worked_minutes,
                'normal_minutes' => $l->normal_minutes,
                'beyond_normal_minutes' => $l->beyond_normal_minutes,
                'orp_days' => (string) $l->orp_days,
                'flags' => $l->flags ?? [],
                'note' => $l->note,
                'amount' => $showPay ? (string) $l->amount : null,
            ])->values()->all(),
        ];
    }

    public function checkCompletion(OvertimeClaim $claim): ApprovalCheck
    {
        if ($claim->cap_exceeded && trim((string) $claim->cap_override_reason) === '') {
            return ApprovalCheck::fail('attendance.approval.cap_reason_required');
        }

        foreach ($claim->lines()->whereNotNull('attendance_id')->get() as $line) {
            $row = Attendance::query()->find($line->attendance_id);
            if ($row === null || ! $row->isLive() || $row->contentHash() !== $line->attendance_hash) {
                return ApprovalCheck::fail('attendance.approval.attendance_changed', ['date' => $line->work_date->format('d/m/Y')]);
            }
        }

        // Salary components arrive with Payroll (M4); from then a missing component vetoes here.
        return ApprovalCheck::ok();
    }

    public function completed(OvertimeClaim $claim): void
    {
        if ($claim->effects_applied_at !== null) {
            return;
        }
        $claim->forceFill(['effects_applied_at' => CarbonImmutable::now()])->save();
        OvertimeClaimApproved::dispatch($claim);
    }

    /** Rejected / cancelled: the attendance rows become available for a new draft. */
    public function releaseLines(OvertimeClaim $claim): void
    {
        foreach ($claim->lines()->get() as $line) {
            $line->forceFill(['is_active' => false])->save();
        }
    }

    public function cancelled(OvertimeClaim $claim, bool $wasApproved): void
    {
        if ($wasApproved && $claim->posted_payroll_run_id !== null) {
            throw new AttendanceRuleViolation('attendance.errors.claim_posted');
        }
        $this->releaseLines($claim);
        if ($wasApproved) {
            OvertimeClaimCancelled::dispatch($claim);
        }
    }
}
