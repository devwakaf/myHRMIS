<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\AllocationSource;
use App\Domain\Leave\Enums\CreditScheduleKind;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Enums\PostedVia;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeavePolicyRow;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates policy allocations for a leave period (spec-02 §2.5, §3.6, §3.8):
 * main `allocation` lot, tier_change / accrual credit schedules (due rows posted
 * immediately, `generation`), then carry-forward once the period has started.
 * Idempotent: one live allocation per (employee, type, period).
 */
final class AllocationGenerator
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly EntitlementCalculator $calculator,
        private readonly AccrualScheduleBuilder $accruals,
        private readonly CreditPoster $poster,
        private readonly CarryForwardProcessor $carry,
    ) {}

    /**
     * @return list<LeaveAllocation> allocations created
     */
    public function generate(LeavePolicyAssignment $assignment, LeavePeriod $period, ?CarbonImmutable $from = null): array
    {
        return DB::transaction(function () use ($assignment, $period, $from): array {
            $employee = Employee::query()->findOrFail($assignment->employee_id);
            $rows = LeavePolicyRow::query()->where('leave_policy_id', $assignment->leave_policy_id)->with(['leaveType', 'tiers'])->get();
            $created = [];

            foreach ($rows as $row) {
                $type = $row->leaveType;
                if (! $type->is_active || ! in_array($type->entitlement_basis, [EntitlementBasis::PerPeriod, EntitlementBasis::Earned], true)) {
                    continue;
                }
                if (! LeaveEligibility::isEligible($employee, $type)) {
                    continue;
                }

                $windowFrom = $period->from_date->max($employee->date_of_joining)->max($assignment->effective_from);
                if ($from !== null) {
                    $windowFrom = $windowFrom->max($from);
                }
                $windowTo = $period->to_date;
                if ($assignment->effective_to !== null) {
                    $windowTo = $windowTo->min($assignment->effective_to);
                }
                if ($employee->relieving_date !== null) {
                    $windowTo = $windowTo->min($employee->relieving_date);
                }
                if ($windowFrom->greaterThan($windowTo)) {
                    continue;
                }

                $this->lockKey($employee->id, $type->id);
                if ($this->liveAllocation($employee->id, $type->id, $period->id) !== null) {
                    continue;
                }

                $created[] = $this->createPolicyAllocation($employee, $type, $row, $assignment, $period, new DateRange($windowFrom, $windowTo));
            }

            return $created;
        });
    }

    public function createPolicyAllocation(Employee $employee, LeaveType $type, LeavePolicyRow $row, LeavePolicyAssignment $assignment, LeavePeriod $period, DateRange $window): LeaveAllocation
    {
        $windowTo = $window->to ?? $period->to_date;
        $today = LeaveLedger::today();

        $allocation = LeaveAllocation::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_period_id' => $period->id,
            'from_date' => $window->from->toDateString(),
            'to_date' => $windowTo->toDateString(),
            'source' => AllocationSource::Policy,
            'leave_policy_assignment_id' => $assignment->id,
            'leave_policy_row_id' => $row->id,
        ]);
        $allocation->transitionTo(DocumentStatus::Submitted, null);

        if ($type->entitlement_basis === EntitlementBasis::Earned) {
            $this->ledger->credit($allocation, MovementKind::Allocation, '0', $window->from, $windowTo, $today, $allocation);
            $schedule = $this->accruals->build($employee, $type, $row, $period, $window);
            $total = '0';
            foreach ($schedule as $item) {
                $this->schedule($allocation, CreditScheduleKind::Accrual, $item['due_date'], $item['days']);
                $total = Dec::add($total, $item['days']);
            }
            $allocation->forceFill(['entitlement_days' => $total])->save();
        } else {
            $ent = $this->calculator->calculate($employee, $type, $period, [['from' => $window->from, 'to' => $windowTo, 'row' => $row]]);
            $this->ledger->credit($allocation, MovementKind::Allocation, $ent->initial, $window->from, $windowTo, $today, $allocation);
            $previous = $ent->initial;
            foreach ($ent->boundaries as $boundary) {
                $this->schedule($allocation, CreditScheduleKind::TierChange, $boundary['date'], Dec::max(Dec::sub($boundary['cumulative'], $previous), '0'));
                $previous = $boundary['cumulative'];
            }
            $allocation->forceFill(['entitlement_days' => $ent->total])->save();
        }

        $this->postDue($allocation, $today);

        if ($period->from_date->lessThanOrEqualTo($today)) {
            $this->carry->process($allocation);
        }

        return $allocation->refresh();
    }

    /** Allocation for Cuti Gantian grants (0 entitlement, source `compensatory`). */
    public function ensureCompensatoryAllocation(Employee $employee, LeaveType $type, LeavePeriod $period): LeaveAllocation
    {
        $this->lockKey($employee->id, $type->id);
        $existing = $this->liveAllocation($employee->id, $type->id, $period->id);
        if ($existing !== null) {
            return $existing;
        }

        $from = $period->from_date->max($employee->date_of_joining);
        $allocation = LeaveAllocation::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_period_id' => $period->id,
            'from_date' => $from->toDateString(),
            'to_date' => $period->to_date->toDateString(),
            'source' => AllocationSource::Compensatory,
        ]);
        $allocation->transitionTo(DocumentStatus::Submitted, null);
        $this->ledger->credit($allocation, MovementKind::Allocation, '0', $from, $period->to_date, LeaveLedger::today(), $allocation);

        return $allocation;
    }

    public function liveAllocation(int $employeeId, int $leaveTypeId, int $periodId): ?LeaveAllocation
    {
        return LeaveAllocation::query()->live()
            ->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('leave_period_id', $periodId)
            ->first();
    }

    /** Post every pending row due on or before $today (generation-time catch-up). */
    public function postDue(LeaveAllocation $allocation, CarbonImmutable $today): void
    {
        $due = LeaveCreditSchedule::query()->where('leave_allocation_id', $allocation->id)
            ->where('status', CreditScheduleStatus::Pending->value)
            ->whereDate('due_date', '<=', $today->toDateString())
            ->orderBy('due_date')->get();

        foreach ($due as $row) {
            $this->poster->post($row, PostedVia::Generation);
        }
    }

    private function schedule(LeaveAllocation $allocation, CreditScheduleKind $kind, CarbonImmutable $due, string $days): void
    {
        LeaveCreditSchedule::query()->firstOrCreate(
            ['leave_allocation_id' => $allocation->id, 'due_date' => $due->toDateString(), 'kind' => $kind->value],
            ['organisation_id' => $allocation->organisation_id, 'days' => $days, 'status' => CreditScheduleStatus::Pending],
        );
    }

    /** Serialise generation per (employee, type) by locking the employee row. */
    private function lockKey(int $employeeId, int $leaveTypeId): void
    {
        Employee::query()->whereKey($employeeId)->lockForUpdate()->value('id');
        LeaveAllocation::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->lockForUpdate()->get(['id']);
    }
}
