<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeEligibility;
use App\Domain\Leave\Enums\CreditScheduleKind;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Enums\PostedVia;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Posts one Jadual Kredit Cuti row (spec-02 §2.7, §3.6, §3.8). Accrual rows post
 * their precomputed days; tier_change rows recompute E through that boundary
 * minus what is already credited. Failures are recorded for HR retry.
 */
final class CreditPoster
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly EntitlementCalculator $calculator,
    ) {}

    /**
     * @return CreditScheduleStatus the resulting status
     */
    public function post(LeaveCreditSchedule $schedule, PostedVia $via): CreditScheduleStatus
    {
        return DB::transaction(function () use ($schedule, $via): CreditScheduleStatus {
            /** @var LeaveCreditSchedule $schedule */
            $schedule = LeaveCreditSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
            if (in_array($schedule->status, [CreditScheduleStatus::Posted, CreditScheduleStatus::Skipped], true)) {
                return $schedule->status;
            }

            $allocation = $schedule->allocation()->with(['policyRow.tiers', 'policyRow.leaveType', 'period', 'leaveType'])->firstOrFail();
            $employee = Employee::query()->findOrFail($allocation->employee_id);
            $due = $schedule->due_date;

            if ($allocation->status !== DocumentStatus::Submitted) {
                return $this->finish($schedule, CreditScheduleStatus::Skipped, $via, 'allocation_cancelled');
            }
            if ($employee->relieving_date !== null && $due->greaterThan($employee->relieving_date)) {
                return $this->finish($schedule, CreditScheduleStatus::Skipped, $via, 'after_relieving');
            }
            if (! EmployeeEligibility::isActiveOn($employee, $due) || $employee->status === EmployeeStatus::Suspended) {
                return $this->finish($schedule, CreditScheduleStatus::Failed, $via, (string) __('leave.errors.credit_employee_inactive'));
            }

            $days = (string) $schedule->days;
            $kind = MovementKind::Accrual;

            if ($schedule->kind === CreditScheduleKind::TierChange) {
                $kind = MovementKind::TierChange;
                $row = $allocation->policyRow;
                if ($row === null) {
                    return $this->finish($schedule, CreditScheduleStatus::Skipped, $via, 'no_policy_row');
                }
                $ent = $this->calculator->forRow($employee, $row, $allocation->period, $allocation->from_date, $allocation->to_date);
                $target = $ent->total;
                foreach ($ent->boundaries as $boundary) {
                    if ($boundary['date']->equalTo($due)) {
                        $target = $boundary['cumulative'];
                    }
                }
                $days = Dec::sub($target, $this->ledger->creditedEntitlement($allocation));
            }

            if (! Dec::isPositive($days)) {
                return $this->finish($schedule, CreditScheduleStatus::Skipped, $via, 'nothing_due');
            }

            $movement = $this->ledger->credit($allocation, $kind, $days, $due, $allocation->to_date, $due, $schedule);
            $schedule->forceFill([
                'status' => CreditScheduleStatus::Posted,
                'posted_via' => $via,
                'attempted_at' => now(),
                'movement_id' => $movement->id,
                'failure_reason' => null,
                'days' => $days,
            ])->save();

            return CreditScheduleStatus::Posted;
        });
    }

    private function finish(LeaveCreditSchedule $schedule, CreditScheduleStatus $status, PostedVia $via, string $reason): CreditScheduleStatus
    {
        $schedule->forceFill([
            'status' => $status,
            'posted_via' => $via,
            'attempted_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 255),
        ])->save();

        return $status;
    }
}
