<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Actions\PostApprovedLeave;
use App\Domain\Leave\Actions\ReverseApprovedLeave;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Enums\DaySession;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveApplicationAttachment;
use App\Domain\Leave\Models\LeaveApplicationDay;
use App\Domain\Leave\Policies\LeaveApplicationPolicy;
use App\Domain\Leave\Support\DayBreakdown;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Approval\Enums\ActionType;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Models\ApprovalAction;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Models\ApprovalStepAssignee;
use App\Domain\Shared\Dating\DateRange;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The `leave` Approvable hooks (spec-02 §4.1): condition attributes, summary,
 * decision context, the binding completion re-check, and posting / reversal.
 */
final class LeaveApplicationApproval
{
    public function __construct(
        private readonly LeaveSufficiency $sufficiency,
        private readonly LeaveBalance $balance,
        private readonly BlockListChecker $blockLists,
        private readonly LeaveApplicationValidator $validator,
        private readonly LeaveAttendancePort $attendance,
        private readonly PostApprovedLeave $post,
        private readonly ReverseApprovedLeave $reverse,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(LeaveApplication $app): array
    {
        $type = $app->leaveType;
        $deductible = $app->exists
            ? $app->days()->where('portion', '>', 0)->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d))->all()
            : [];
        $employee = $app->approvalApplicant();

        return [
            'leave.days' => Dec::norm((string) $app->total_days),
            'leave.unpaid_days' => Dec::norm((string) $app->unpaid_days),
            'leave.leave_type_code' => $type->code,
            'leave.entitlement_basis' => $type->entitlement_basis->value,
            'leave.pay_mode' => $type->pay_mode->value,
            'leave.is_backdated' => $app->from_date->lessThan(CarbonImmutable::today()),
            'leave.is_on_behalf' => (bool) $app->is_on_behalf,
            'leave.block_list_hit' => $this->blockLists->hits($employee, $type->id, $deductible) !== [],
        ];
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}
     */
    public function summary(LeaveApplication $app): array
    {
        return [
            'key' => 'leave.summary.application',
            'params' => [
                'type' => $app->leaveType->displayName(),
                'from' => $app->from_date->format('d/m/Y'),
                'to' => $app->to_date->format('d/m/Y'),
                'days' => Dec::short((string) $app->total_days),
            ],
        ];
    }

    /**
     * Recomputed live so every step's approver sees current figures.
     *
     * @return array<string, mixed>
     */
    public function decisionContext(LeaveApplication $app, User $viewer): array
    {
        $app->loadMissing(['leaveType', 'days', 'attachments']);
        $employee = $app->approvalApplicant();
        $type = $app->leaveType;
        $balance = $this->balance->on($employee, $type, $app->from_date, $app->id);
        $deductible = $this->deductible($app);
        $mayDownload = app(LeaveApplicationPolicy::class)->viewAttachments($viewer, $app);

        return [
            'type' => ['code' => $type->code, 'name' => $type->displayName(), 'pay_mode' => $type->pay_mode->value, 'has_balance' => $type->hasBalance()],
            'from_date' => $app->from_date->toDateString(),
            'to_date' => $app->to_date->toDateString(),
            'total_days' => Dec::norm((string) $app->total_days),
            'unpaid_days' => Dec::norm((string) $app->unpaid_days),
            'reason' => $app->reason,
            'days' => $app->days->map(fn (LeaveApplicationDay $d) => [
                'date' => $d->date->toDateString(),
                'day_kind' => $d->day_kind->value,
                'day_kind_label' => $d->day_kind->label(),
                'session' => $d->session->value,
                'portion' => (string) $d->portion,
                'unpaid_portion' => (string) $d->unpaid_portion,
            ])->values()->all(),
            'balance_before' => $balance['available'],
            'balance_after' => $type->hasBalance() ? Dec::sub($balance['available'], (string) $app->total_days) : null,
            'reserved_by_others' => $balance['reserved'],
            'caps' => $balance['caps'],
            'expiring' => $balance['expiring'],
            'block_list_hits' => $this->blockLists->hits($employee, $type->id, array_map(fn (array $d) => $d['date'], $deductible)),
            'is_backdated' => $app->from_date->lessThan(CarbonImmutable::today()),
            'is_on_behalf' => (bool) $app->is_on_behalf,
            'attachments' => $app->attachments->map(fn (LeaveApplicationAttachment $a) => [
                'id' => $a->id,
                'kind' => $a->kind->value,
                'kind_label' => $a->kind->label(),
                'original_name' => $a->original_name,
                'download_url' => $mayDownload ? '/leave/attachments/'.$a->id.'/download' : null,
            ])->values()->all(),
        ];
    }

    /**
     * Binding re-check before the final approval, inside the engine transaction.
     */
    public function checkCompletion(LeaveApplication $app, ApprovalRequest $request): ApprovalCheck
    {
        $app->loadMissing(['leaveType', 'days']);
        $employee = $app->approvalApplicant();
        $type = $app->leaveType;
        $deductible = $this->deductible($app);

        $result = $this->sufficiency->check($employee, $type, $deductible, $app->id);
        if (! $result['ok']) {
            $key = $result['error'] === 'leave.errors.aggregate_cap' ? 'leave.approval.aggregate_cap' : 'leave.approval.insufficient_balance';

            return ApprovalCheck::fail($key, $result['params']);
        }

        $conflicts = $this->attendance->conflicts($employee, new DateRange($app->from_date, $app->to_date));
        foreach ($conflicts as $conflict) {
            $day = $app->days->first(fn (LeaveApplicationDay $d) => $d->date->toDateString() === $conflict['date']);
            if ($day === null || ! Dec::isPositive((string) $day->portion)) {
                continue;
            }
            $worked = DaySession::tryFrom($conflict['session']) ?? DaySession::Full;
            if ($day->session === DaySession::Full || $worked === DaySession::Full || $worked === $day->session) {
                return ApprovalCheck::fail('leave.approval.attendance_conflict', ['date' => $day->date->format('d/m/Y')]);
            }
        }

        [$lockedUnpaid] = $this->validator->lockedDates($employee, $this->breakdownOf($app));
        if ($lockedUnpaid !== []) {
            return ApprovalCheck::fail('leave.approval.payroll_locked', ['dates' => implode(', ', $lockedUnpaid)]);
        }

        $hits = $this->blockLists->hits($employee, $type->id, array_map(fn (array $d) => $d['date'], $deductible));
        if ($hits !== []) {
            $approvers = ApprovalStepAssignee::query()
                ->whereHas('step', fn ($q) => $q->where('approval_request_id', $request->id))
                ->where('decision', AssigneeDecision::Approved->value)
                ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

            $lastDecision = ApprovalAction::query()->where('approval_request_id', $request->id)
                ->whereIn('action', [ActionType::Approved->value, ActionType::OverrideApproved->value])
                ->orderByDesc('id')->first();
            $overridden = $lastDecision !== null && $lastDecision->action === ActionType::OverrideApproved && trim((string) $lastDecision->note) !== '';

            if (! $overridden && ! $this->blockLists->exempted($hits, $approvers)) {
                return ApprovalCheck::fail('leave.approval.block_list', ['dates' => implode(', ', array_unique(array_map(fn ($h) => CarbonImmutable::parse($h['date'])->format('d/m/Y'), $hits)))]);
            }
        }

        return ApprovalCheck::ok();
    }

    public function completed(LeaveApplication $app): void
    {
        $this->post->handle($app);
    }

    public function cancelledAfterApproval(LeaveApplication $app): void
    {
        $this->reverse->handle($app);
    }

    /**
     * @return list<array{date: CarbonImmutable, portion: string}>
     */
    public function deductible(LeaveApplication $app): array
    {
        return $app->days->filter(fn (LeaveApplicationDay $d) => Dec::isPositive((string) $d->portion))
            ->map(fn (LeaveApplicationDay $d) => ['date' => $d->date, 'portion' => (string) $d->portion])
            ->values()->all();
    }

    /** Rebuild a breakdown object from the stored day rows. */
    public function breakdownOf(LeaveApplication $app): DayBreakdown
    {
        $days = $app->days->map(fn (LeaveApplicationDay $d) => [
            'date' => $d->date,
            'day_kind' => $d->day_kind,
            'session' => $d->session,
            'portion' => (string) $d->portion,
            'unpaid_portion' => (string) $d->unpaid_portion,
            'holiday_list_id' => $d->holiday_list_id,
            'holiday_name' => null,
        ])->values()->all();

        return new DayBreakdown($days, (string) $app->total_days, (string) $app->unpaid_days, count($days));
    }

    public function employeeOf(LeaveApplication $app): Employee
    {
        return $app->approvalApplicant();
    }
}
