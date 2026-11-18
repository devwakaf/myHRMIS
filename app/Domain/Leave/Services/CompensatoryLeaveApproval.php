<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\CompensatoryLeaveRequest;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Lifecycle\ApprovalStatus;

/**
 * Cuti Gantian (spec-02 §3.16): validation of worked rest days / holidays and the
 * `compensatory_leave` Approvable hooks (grant on completion, revoke on cancel).
 */
final class CompensatoryLeaveApproval
{
    public function __construct(
        private readonly HolidayResolver $holidays,
        private readonly LeaveAttendancePort $attendance,
        private readonly AllocationGenerator $generator,
        private readonly LeaveLedger $ledger,
    ) {}

    /**
     * Errors keyed by field; also returns whether attendance could not be verified.
     *
     * @return array{errors: array<string, string>, unverified: bool, days: string, includes_public_holiday: bool}
     */
    public function validate(CompensatoryLeaveRequest $request): array
    {
        $errors = [];
        $employee = Employee::query()->findOrFail($request->employee_id);
        $type = $request->leaveType;
        $unverified = false;
        $publicHoliday = false;

        if ($type->entitlement_basis !== EntitlementBasis::Compensatory || ! $type->is_active) {
            $errors['leave_type_id'] = __('leave.errors.not_compensatory_type');
        }
        if ($request->work_to->lessThan($request->work_from)) {
            return ['errors' => ['work_to' => __('leave.errors.to_before_from')], 'unverified' => false, 'days' => '0', 'includes_public_holiday' => false];
        }
        if ($request->work_from->diffInDays($request->work_to) > 31) {
            $errors['work_to'] = __('leave.errors.compensatory_range_too_long');
        }
        if (trim((string) $request->reason) === '') {
            $errors['reason'] = __('leave.errors.reason_required');
        }
        if ($request->half_day_date !== null && ! $request->half_day_date->betweenIncluded($request->work_from, $request->work_to)) {
            $errors['half_day_date'] = __('leave.errors.half_day_edge_only');
        }
        if (! $employee->date_of_joining->lessThanOrEqualTo($request->work_from)) {
            $errors['work_from'] = __('leave.errors.before_joining', ['date' => $employee->date_of_joining->format('d/m/Y')]);
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'unverified' => false, 'days' => '0', 'includes_public_holiday' => false];
        }

        $rows = $this->holidays->holidaysBetween($employee, $request->work_from, $request->work_to);
        $notRest = [];
        $notWorked = [];
        $count = 0;
        foreach ($request->range()->dates() as $date) {
            $count++;
            $row = $rows->get($date->toDateString());
            if ($row === null) {
                $notRest[] = $date->format('d/m/Y');

                continue;
            }
            if (in_array($row['kind'], [HolidayKind::PublicHoliday->value, HolidayKind::State->value], true)) {
                $publicHoliday = true;
            }
            $worked = $this->attendance->workedOn($employee, $date);
            if ($worked === null) {
                $notWorked[] = $date->format('d/m/Y');
            } elseif (! $worked->verified) {
                $unverified = true;
            }
        }

        if ($notRest !== []) {
            $errors['work_from'] = __('leave.errors.compensatory_not_rest_day', ['dates' => implode(', ', $notRest)]);
        }
        if ($notWorked !== []) {
            $errors['work_from'] = __('leave.errors.compensatory_not_worked', ['dates' => implode(', ', $notWorked)]);
        }

        $overlap = CompensatoryLeaveRequest::query()
            ->where('employee_id', $request->employee_id)
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->when($request->exists, fn ($q) => $q->whereKeyNot($request->id))
            ->whereDate('work_from', '<=', $request->work_to->toDateString())
            ->whereDate('work_to', '>=', $request->work_from->toDateString())
            ->exists();
        if ($overlap) {
            $errors['work_from'] = __('leave.errors.compensatory_overlap');
        }

        $days = Dec::sub((string) $count, $request->half_day_date !== null ? '0.5' : '0');

        return ['errors' => $errors, 'unverified' => $unverified, 'days' => $days, 'includes_public_holiday' => $publicHoliday];
    }

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(CompensatoryLeaveRequest $request): array
    {
        return [
            'compensatory_leave.days' => Dec::norm((string) $request->days_claimed),
            'compensatory_leave.includes_public_holiday' => $this->validate($request)['includes_public_holiday'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decisionContext(CompensatoryLeaveRequest $request): array
    {
        return [
            'type' => $request->leaveType->displayName(),
            'work_from' => $request->work_from->toDateString(),
            'work_to' => $request->work_to->toDateString(),
            'half_day_date' => $request->half_day_date?->toDateString(),
            'days_claimed' => Dec::norm((string) $request->days_claimed),
            'reason' => $request->reason,
            'attendance_unverified' => $request->attendance_unverified,
            'expiry_days' => $request->leaveType->compensatory_expiry_days,
        ];
    }

    public function checkCompletion(CompensatoryLeaveRequest $request): ApprovalCheck
    {
        $outcome = $this->validate($request);
        if ($outcome['errors'] !== []) {
            return ApprovalCheck::fail('leave.approval.compensatory_invalid', ['message' => (string) array_values($outcome['errors'])[0]]);
        }

        return ApprovalCheck::ok();
    }

    /** Grant one lot per worked date (0.5 for the half day), inside the approval transaction. */
    public function grant(CompensatoryLeaveRequest $request): void
    {
        $employee = Employee::query()->findOrFail($request->employee_id);
        $type = $request->leaveType;
        $period = LeavePeriod::containing($request->work_to);
        if ($period === null) {
            throw new LeaveRuleViolation('leave.errors.no_period', ['date' => $request->work_to->format('d/m/Y')]);
        }

        $allocation = $this->generator->ensureCompensatoryAllocation($employee, $type, $period);
        $today = LeaveLedger::today();
        $ids = [];

        foreach ($request->range()->dates() as $date) {
            $days = $request->half_day_date !== null && $request->half_day_date->equalTo($date) ? '0.5' : '1';
            $validFrom = $date->addDay();
            $limit = $type->compensatory_expiry_days === null ? $period->to_date : $date->addDays($type->compensatory_expiry_days);
            $validTo = $limit->min($period->to_date)->max($validFrom);
            $ids[] = $this->ledger->credit($allocation, MovementKind::Compensatory, $days, $validFrom, $validTo, $today, $request)->id;
        }

        $request->forceFill(['granted_movement_ids' => $ids])->save();
    }

    /** Reverse the grant; refused once any granted lot has been used. */
    public function revoke(CompensatoryLeaveRequest $request): void
    {
        $lots = LeaveMovement::query()->whereIn('id', $request->granted_movement_ids ?? [])->get();
        $today = LeaveLedger::today();

        foreach ($lots as $lot) {
            $used = $this->ledger->unreversed(LeaveMovement::query()->where('lot_id', $lot->id)
                ->where('kind', '!=', MovementKind::Expiry->value))->exists();
            if ($used) {
                throw new LeaveRuleViolation('leave.errors.compensatory_used', ['date' => $lot->date_from->subDay()->format('d/m/Y')]);
            }
        }

        foreach ($lots as $lot) {
            $this->ledger->reverseAll(LeaveMovement::query()->where('lot_id', $lot->id)->where('kind', MovementKind::Expiry->value), $today, $request);
            $this->ledger->reverse($lot, $today, $request);
        }
    }
}
