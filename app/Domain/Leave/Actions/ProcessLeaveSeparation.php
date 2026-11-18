<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Services\EntitlementCalculator;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Illuminate\Support\Facades\DB;

/**
 * Separation (spec-02 §3.7), run on EmployeeSeparated: pro-rate entitlement to
 * the relieving date (`separation_prorate` debit, may leave a negative balance
 * for HR's Baki Negatif list), skip later credits, withdraw pending and cancel
 * approved leave dated after the relieving date (system actor).
 */
final class ProcessLeaveSeparation
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly EntitlementCalculator $calculator,
        private readonly CancelLeaveApplication $cancel,
    ) {}

    /**
     * @return array{prorated: int, skipped: int, withdrawn: int, cancelled: int}
     */
    public function handle(Employee $employee): array
    {
        $relieving = $employee->relieving_date;
        $out = ['prorated' => 0, 'skipped' => 0, 'withdrawn' => 0, 'cancelled' => 0];
        if ($relieving === null) {
            return $out;
        }

        DB::transaction(function () use ($employee, $relieving, &$out): void {
            $allocations = LeaveAllocation::query()->live()
                ->where('employee_id', $employee->id)
                ->whereDate('from_date', '<=', $relieving->toDateString())
                ->whereDate('to_date', '>', $relieving->toDateString())
                ->with(['leaveType', 'policyRow.tiers', 'policyRow.leaveType', 'period'])
                ->get();

            foreach ($allocations as $allocation) {
                $type = $allocation->leaveType;
                if (! $type->prorate_on_separation || $allocation->policyRow === null) {
                    continue;
                }
                $already = $this->ledger->unreversed(LeaveMovement::query()->where('leave_allocation_id', $allocation->id)
                    ->where('kind', MovementKind::SeparationProrate->value))->exists();
                if ($already) {
                    continue;
                }

                $ent = $this->calculator->forRow($employee, $allocation->policyRow, $allocation->period, $allocation->from_date, $relieving, $type->proration_method);
                $excess = Dec::sub($this->ledger->creditedEntitlement($allocation), $ent->total);
                if (! Dec::isPositive($excess)) {
                    continue;
                }
                $main = $this->ledger->mainLot($allocation);
                if ($main === null) {
                    continue;
                }
                $this->ledger->debit($main, MovementKind::SeparationProrate, $excess, $relieving, $relieving, LeaveLedger::today(), $allocation, '0', 'separation');
                $out['prorated']++;
            }

            $out['skipped'] = LeaveCreditSchedule::query()
                ->where('status', CreditScheduleStatus::Pending->value)
                ->whereDate('due_date', '>', $relieving->toDateString())
                ->whereHas('allocation', fn ($q) => $q->where('employee_id', $employee->id))
                ->update(['status' => CreditScheduleStatus::Skipped->value, 'failure_reason' => 'after_relieving', 'attempted_at' => now()]);
        });

        $later = LeaveApplication::query()->where('employee_id', $employee->id)
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->whereDate('from_date', '>', $relieving->toDateString())
            ->get();
        $reason = (string) __('leave.separation.cancel_reason');
        foreach ($later as $application) {
            if ($application->status === ApprovalStatus::Pending) {
                $this->cancel->withdraw($application, null, $reason);
                $out['withdrawn']++;
            } else {
                $this->cancel->cancelApproved($application, null, $reason);
                $out['cancelled']++;
            }
        }

        return $out;
    }
}
