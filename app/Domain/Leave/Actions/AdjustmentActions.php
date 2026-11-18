<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Enums\AdjustmentDirection;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAdjustment;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Services\LotPool;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pelarasan Cuti (spec-02 §2.9): add credits a lot (own expiry), reduce consumes
 * lots usable on the effective date (soonest expiry first). Cancel = reversal.
 */
final class AdjustmentActions
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /**
     * @param  array{leave_allocation_id: int, direction: string, days: string|int, effective_date: string, expires_on?: string|null, reason: string}  $data
     */
    public function saveDraft(?LeaveAdjustment $adjustment, array $data): LeaveAdjustment
    {
        $allocation = LeaveAllocation::query()->live()->find($data['leave_allocation_id']);
        $errors = [];
        if ($allocation === null) {
            $errors['leave_allocation_id'] = __('leave.errors.invalid_allocation');
        }
        if (! is_numeric($data['days']) || ! Dec::isPositive((string) $data['days'])) {
            $errors['days'] = __('leave.errors.days_positive');
        }
        if ($allocation !== null) {
            $effective = CarbonImmutable::parse($data['effective_date']);
            if (! $effective->betweenIncluded($allocation->from_date, $allocation->to_date)) {
                $errors['effective_date'] = __('leave.errors.date_outside_allocation');
            }
            if (! empty($data['expires_on']) && CarbonImmutable::parse($data['expires_on'])->lessThan($effective)) {
                $errors['expires_on'] = __('leave.errors.to_before_from');
            }
        }
        if (trim((string) $data['reason']) === '') {
            $errors['reason'] = __('leave.errors.reason_required');
        }
        DomainValidation::throwIf($errors);

        $values = [
            'leave_allocation_id' => $data['leave_allocation_id'],
            'direction' => $data['direction'],
            'days' => Dec::norm((string) $data['days']),
            'effective_date' => $data['effective_date'],
            'expires_on' => $data['direction'] === AdjustmentDirection::Add->value ? ($data['expires_on'] ?? null) : null,
            'reason' => $data['reason'],
        ];

        if ($adjustment === null) {
            return LeaveAdjustment::query()->create($values);
        }
        $adjustment->update($values);

        return $adjustment;
    }

    public function submit(LeaveAdjustment $adjustment, User $actor): LeaveAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): LeaveAdjustment {
            $allocation = LeaveAllocation::query()->live()->lockForUpdate()->findOrFail($adjustment->leave_allocation_id);
            $today = LeaveLedger::today();
            $adjustment->transitionTo(DocumentStatus::Submitted, $actor);

            if ($adjustment->direction === AdjustmentDirection::Add) {
                $validTo = $adjustment->expires_on ?? $allocation->to_date;
                $this->ledger->credit($allocation, MovementKind::AdjustmentAdd, (string) $adjustment->days, $adjustment->effective_date, $validTo, $today, $adjustment, $adjustment->reason);

                return $adjustment;
            }

            $pool = LotPool::load($allocation->employee_id, $allocation->leave_type_id, null, $allocation->id);
            $plan = $pool->consume([['date' => $adjustment->effective_date, 'portion' => (string) $adjustment->days]], allowReclaim: false);
            if (Dec::isPositive($plan->shortfall())) {
                throw new LeaveRuleViolation('leave.errors.adjustment_exceeds', ['days' => Dec::short($plan->consumed())]);
            }
            foreach ($plan->perLot as $item) {
                $lot = LeaveMovement::query()->findOrFail($item['lot_id']);
                $this->ledger->debit($lot, MovementKind::AdjustmentReduce, $item['days'], $adjustment->effective_date, $adjustment->effective_date, $today, $adjustment, '0', $adjustment->reason);
            }

            return $adjustment;
        });
    }

    public function cancel(LeaveAdjustment $adjustment, User $actor, string $reason): LeaveAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $reason): LeaveAdjustment {
            $wasSubmitted = $adjustment->status === DocumentStatus::Submitted;
            if ($wasSubmitted && $adjustment->direction === AdjustmentDirection::Add) {
                $lot = LeaveMovement::query()->fromSource($adjustment)->where('kind', MovementKind::AdjustmentAdd->value)->first();
                $used = $lot !== null && $this->ledger->unreversed(LeaveMovement::query()->where('lot_id', $lot->id)
                    ->where('kind', '!=', MovementKind::Expiry->value))->exists();
                if ($used) {
                    throw new LeaveRuleViolation('leave.errors.adjustment_used');
                }
                if ($lot !== null) {
                    $this->ledger->reverseAll(LeaveMovement::query()->where('lot_id', $lot->id)->where('kind', MovementKind::Expiry->value), LeaveLedger::today(), $adjustment);
                }
            }

            $adjustment->transitionTo(DocumentStatus::Cancelled, $actor, $reason);
            if ($wasSubmitted) {
                $this->ledger->reverseAll(LeaveMovement::query()->fromSource($adjustment)
                    ->whereIn('kind', [MovementKind::AdjustmentAdd->value, MovementKind::AdjustmentReduce->value]), LeaveLedger::today(), $adjustment, $reason);
            }

            return $adjustment;
        });
    }
}
