<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\RequestDayOutcome;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The `attendance_request` Approvable hooks (spec-03 §4.8, §4.12).
 */
final class AttendanceRequestApproval
{
    public function __construct(private readonly AttendanceRequestPlanner $planner) {}

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(AttendanceRequest $r): array
    {
        $days = (string) ((int) $r->from_date->diffInDays($r->to_date) + 1);
        if ($r->is_half_day) {
            $days = bcsub($days, '0.5', 1);
        }

        return [
            'attendance_request.kind' => $r->kind->value,
            'attendance_request.days' => $days,
            'attendance_request.is_backdated' => $r->from_date->lessThan(CarbonImmutable::today()),
            'attendance_request.includes_rest_days' => (bool) $r->include_rest_days,
        ];
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}
     */
    public function summary(AttendanceRequest $r): array
    {
        return [
            'key' => 'attendance.summary.attendance_request',
            'params' => [
                'kind' => $r->kind->label(),
                'from' => $r->from_date->format('d/m/Y'),
                'to' => $r->to_date->format('d/m/Y'),
                'days' => (string) ((int) $r->from_date->diffInDays($r->to_date) + 1),
            ],
        ];
    }

    /**
     * Live per-day preview (recomputed for every approver).
     *
     * @return array<string, mixed>
     */
    public function decisionContext(AttendanceRequest $r, User $viewer): array
    {
        return [
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'from_date' => $r->from_date->toDateString(),
            'to_date' => $r->to_date->toDateString(),
            'is_half_day' => $r->is_half_day,
            'half_day_date' => $r->half_day_date?->toDateString(),
            'half_day_session' => $r->half_day_session?->value,
            'include_rest_days' => $r->include_rest_days,
            'duty_location' => $r->duty_location,
            'explanation' => $r->explanation,
            'preview' => self::presentPlan($this->planner->plan($r)),
        ];
    }

    public function checkCompletion(AttendanceRequest $r): ApprovalCheck
    {
        $plan = $this->planner->plan($r);
        $locked = array_values(array_filter($plan, fn (array $d) => $d['outcome'] === RequestDayOutcome::SkippedLocked));
        if ($locked !== []) {
            return ApprovalCheck::fail('attendance.approval.period_locked', [
                'dates' => implode(', ', array_map(fn (array $d) => $d['date']->format('d/m/Y'), $locked)),
            ]);
        }
        if (AttendanceRequestPlanner::nothingToApply($plan)) {
            return ApprovalCheck::fail('attendance.approval.nothing_to_apply');
        }

        return ApprovalCheck::ok();
    }

    public function completed(AttendanceRequest $r): void
    {
        if ($r->effects_applied_at !== null) {
            return; // idempotency guard
        }

        $this->planner->apply($r);
        $r->forceFill(['effects_applied_at' => CarbonImmutable::now()])->save();
    }

    public function revert(AttendanceRequest $r): void
    {
        if ($r->effects_applied_at === null) {
            return;
        }
        $this->planner->revert($r);
    }

    /**
     * @param  list<array{date: CarbonImmutable, outcome: RequestDayOutcome, existing: Attendance|null, half: bool, day_type: string}>  $plan
     * @return list<array<string, mixed>>
     */
    public static function presentPlan(array $plan): array
    {
        return array_map(fn (array $d) => [
            'date' => $d['date']->toDateString(),
            'outcome' => $d['outcome']->value,
            'outcome_label' => $d['outcome']->label(),
            'half_day' => $d['half'],
            'day_type' => $d['day_type'],
            'existing_status' => $d['existing']?->attendance_status->value,
        ], $plan);
    }
}
