<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Carry-forward (spec-02 §3.11): unused leave of the immediately preceding
 * allocation moves into the new one as a `carry_in` lot (with its own expiry),
 * balanced by `carry_out` debits on the old lots (soonest expiry first).
 */
final class CarryForwardProcessor
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /** Returns the carried days ('0' when nothing was carried). Idempotent via carry_processed_at. */
    public function process(LeaveAllocation $allocation): string
    {
        return DB::transaction(function () use ($allocation): string {
            /** @var LeaveAllocation $allocation */
            $allocation = LeaveAllocation::query()->lockForUpdate()->with(['leaveType', 'policyRow', 'period'])->findOrFail($allocation->id);
            if ($allocation->carry_processed_at !== null || $allocation->status !== DocumentStatus::Submitted) {
                return '0';
            }

            $carried = '0';
            $type = $allocation->leaveType;
            $previous = $type->allow_carry_forward ? $this->previousAllocation($allocation) : null;

            if ($previous !== null) {
                $excluded = $type->carry_includes_carried ? [] : [MovementKind::CarryIn];
                $kinds = array_values(array_filter(MovementKind::credits(), fn (MovementKind $k) => ! in_array($k, $excluded, true)));
                $pool = LotPool::load($previous->employee_id, $previous->leave_type_id, $kinds, $previous->id);

                $unused = '0';
                foreach ($pool->lots() as $lot) {
                    $unused = Dec::add($unused, Dec::max($lot['remaining'], '0'), $lot['reclaimable']);
                }

                $max = $allocation->policyRow->override_max_carry_forward_days ?? $type->max_carry_forward_days;
                $carry = $max === null ? $unused : Dec::min($unused, (string) $max);
                if ($type->max_days_per_period !== null) {
                    $credits = $this->creditsIn($allocation);
                    $carry = Dec::min($carry, Dec::max(Dec::sub((string) $type->max_days_per_period, $credits), '0'));
                }
                $carry = Dec::max($carry, '0');

                if (Dec::isPositive($carry)) {
                    $this->moveDays($previous, $allocation, $pool, $carry);
                    $carried = $carry;
                }
            }

            $allocation->forceFill(['carry_processed_at' => now()])->save();

            return $carried;
        });
    }

    /**
     * "Kira Semula Bawa Ke Hadapan" (§3.11): undo an unused carry and run it again
     * (e.g. after retro leave was approved in the old period). Refused when part of
     * the carried lot has already been used.
     */
    public function recompute(LeaveAllocation $allocation): string
    {
        return DB::transaction(function () use ($allocation): string {
            $carryIn = LeaveMovement::query()->where('leave_allocation_id', $allocation->id)
                ->where('kind', MovementKind::CarryIn->value)->whereNull('reverses_movement_id');
            $lots = $this->ledger->unreversed($carryIn)->get();

            foreach ($lots as $lot) {
                if (Dec::cmp($this->ledger->remaining($lot), (string) $lot->days) !== 0) {
                    throw new LeaveRuleViolation('leave.errors.carry_already_used');
                }
            }

            $today = LeaveLedger::today();
            foreach ($lots as $lot) {
                $this->ledger->reverse($lot, $today, $allocation, 'recompute');
            }
            $this->ledger->reverseAll(
                LeaveMovement::query()->where('kind', MovementKind::CarryOut->value)->fromSource($allocation),
                $today,
                $allocation,
                'recompute',
            );

            $allocation->forceFill(['carry_processed_at' => null])->save();

            return $this->process($allocation);
        });
    }

    private function moveDays(LeaveAllocation $previous, LeaveAllocation $allocation, LotPool $pool, string $carry): void
    {
        $today = LeaveLedger::today();
        $left = $carry;

        foreach ($pool->lots() as $lotData) {
            if (! Dec::isPositive($left)) {
                break;
            }
            $available = Dec::add(Dec::max($lotData['remaining'], '0'), $lotData['reclaimable']);
            $take = Dec::min($left, $available);
            if (! Dec::isPositive($take)) {
                continue;
            }

            $lot = LeaveMovement::query()->findOrFail($lotData['id']);
            $reclaim = Dec::isPositive($lotData['reclaimable']) && Dec::cmp($take, Dec::max($lotData['remaining'], '0')) > 0;
            if ($reclaim) {
                $this->ledger->unexpire($lot, $today, $allocation);
            }
            $this->ledger->debit($lot, MovementKind::CarryOut, $take, $previous->to_date, $previous->to_date, $today, $allocation);
            if ($reclaim && $lot->date_to->lessThan($today)) {
                $this->ledger->expire($lot, $today, $allocation);
            }
            $left = Dec::sub($left, $take);
        }

        $expiryDays = $allocation->leaveType->carry_forward_expiry_days;
        $validTo = $expiryDays === null ? $allocation->to_date : $allocation->from_date->addDays($expiryDays - 1)->min($allocation->to_date);

        $this->ledger->credit($allocation, MovementKind::CarryIn, $carry, $allocation->from_date, $validTo, $today, $allocation);
    }

    private function previousAllocation(LeaveAllocation $allocation): ?LeaveAllocation
    {
        $employeeIds = $this->employeeChain($allocation->employee_id);

        return LeaveAllocation::query()->withoutGlobalScopes()
            ->whereIn('employee_id', $employeeIds)
            ->where('leave_type_id', $allocation->leave_type_id)
            ->where('status', DocumentStatus::Submitted->value)
            ->whereDate('to_date', '<', $allocation->from_date->toDateString())
            ->where('organisation_id', $allocation->organisation_id)
            ->orderByDesc('to_date')
            ->first();
    }

    /**
     * This record plus its previous_employee_id ancestors in the same organisation.
     *
     * @return list<int>
     */
    private function employeeChain(int $employeeId): array
    {
        $ids = [];
        $current = Employee::query()->find($employeeId);
        while ($current !== null && ! in_array($current->id, $ids, true)) {
            $ids[] = $current->id;
            $current = $current->previous_employee_id === null ? null : Employee::query()->find($current->previous_employee_id);
        }

        return $ids;
    }

    private function creditsIn(LeaveAllocation $allocation): string
    {
        return Dec::norm((string) LeaveMovement::query()->where('leave_allocation_id', $allocation->id)
            ->whereIn('kind', MovementKind::creditValues())->whereNull('lot_id')->sum('days'));
    }
}
