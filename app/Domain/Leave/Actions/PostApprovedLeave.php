<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveApplicationDay;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Services\LotPool;
use App\Domain\Leave\Support\Dec;
use Carbon\CarbonImmutable;

/**
 * Posting an approved application (spec-02 §3.5). Called only from
 * LeaveApplication::onApprovalCompleted(), inside the engine's transaction,
 * after the completion veto passed with the allocation rows locked.
 */
final class PostApprovedLeave
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeaveAttendancePort $attendance,
    ) {}

    public function handle(LeaveApplication $application, bool $markAttendance = true): void
    {
        $application->loadMissing(['leaveType', 'days']);
        $type = $application->leaveType;
        $today = LeaveLedger::today();
        $days = $application->days;

        if (! $type->hasBalance()) {
            $this->ledger->debit(
                null, MovementKind::Application, (string) $application->total_days,
                $application->from_date, $application->to_date, $today, $application, (string) $application->unpaid_days,
                owner: ['employee_id' => $application->employee_id, 'leave_type_id' => $type->id, 'organisation_id' => $application->organisation_id],
            );
            if ($markAttendance) {
                $this->attendance->markLeave($application);
            }

            return;
        }

        $deductible = $days->filter(fn (LeaveApplicationDay $d) => Dec::isPositive($d->portion))
            ->map(fn (LeaveApplicationDay $d) => ['date' => $d->date, 'portion' => (string) $d->portion])->values()->all();

        $pool = LotPool::load($application->employee_id, $type->id);
        $plan = $pool->consume($deductible);

        if (Dec::isPositive($plan->shortfall()) && ! $type->allow_negative) {
            // Defensive guard: §3.4 should have caught it; throwing rolls the decision back.
            throw new LeaveRuleViolation('leave.errors.insufficient_balance', ['short' => Dec::short($plan->shortfall()), 'reserved' => '0']);
        }

        $unpaidFraction = $type->unpaidFraction();
        $reclaimedLots = [];

        foreach ($plan->perLot as $item) {
            $lot = LeaveMovement::query()->findOrFail($item['lot_id']);
            if (Dec::isPositive($item['reclaimed'])) {
                $this->ledger->unexpire($lot, $today, $application);
                $reclaimedLots[] = $lot;
            }
            $this->ledger->debit($lot, MovementKind::Application, $item['days'], $item['from'], $item['to'], $today, $application, Dec::mul($item['days'], $unpaidFraction));
        }

        // Shortfall allowed by allow_negative: debit the main lot of the allocation covering each date.
        $byAllocation = [];
        foreach ($plan->unmet as $date => $missing) {
            $allocation = $this->allocationCovering($application, CarbonImmutable::parse($date));
            if ($allocation === null) {
                throw new LeaveRuleViolation('leave.errors.no_allocation', ['dates' => CarbonImmutable::parse($date)->format('d/m/Y')]);
            }
            $plan->allocationByDate[$date] = $allocation->id;
            $byAllocation[$allocation->id] ??= ['allocation' => $allocation, 'days' => '0', 'from' => $date, 'to' => $date];
            $byAllocation[$allocation->id]['days'] = Dec::add($byAllocation[$allocation->id]['days'], $missing);
            $byAllocation[$allocation->id]['to'] = $date;
        }
        foreach ($byAllocation as $entry) {
            $main = $this->ledger->mainLot($entry['allocation']);
            if ($main === null) {
                throw new LeaveRuleViolation('leave.errors.no_allocation', ['dates' => $entry['from']]);
            }
            $this->ledger->debit($main, MovementKind::Application, $entry['days'], CarbonImmutable::parse($entry['from']), CarbonImmutable::parse($entry['to']), $today, $application, Dec::mul($entry['days'], $unpaidFraction), 'negative_balance');
        }

        foreach ($reclaimedLots as $lot) {
            if ($lot->date_to->lessThan($today)) {
                $this->ledger->expire($lot, $today, $application);
            }
        }

        foreach ($days as $day) {
            $allocationId = $plan->allocationByDate[$day->date->toDateString()] ?? $this->allocationCovering($application, $day->date)?->id;
            if ($day->leave_allocation_id !== $allocationId) {
                $day->forceFill(['leave_allocation_id' => $allocationId])->save();
            }
        }

        if ($markAttendance) {
            $this->attendance->markLeave($application);
        }
    }

    private function allocationCovering(LeaveApplication $application, CarbonImmutable $date): ?LeaveAllocation
    {
        return LeaveAllocation::query()->live()
            ->where('employee_id', $application->employee_id)->where('leave_type_id', $application->leave_type_id)
            ->whereDate('from_date', '<=', $date->toDateString())->whereDate('to_date', '>=', $date->toDateString())
            ->first();
    }
}
