<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Carbon\CarbonImmutable;

/**
 * In-memory snapshot of an employee's lots for one leave type, used to simulate
 * and plan consumption (spec-02 §3.2): a day is consumed from the lots usable on
 * that date ordered by earliest expiry, then earliest start, then id. A day may
 * split across lots. Days already expired on an ended lot may be reclaimed for a
 * retro leave date inside that lot's window (§3.5 step 2).
 */
final class LotPool
{
    /**
     * @var array<int, array{id: int, allocation_id: int|null, kind: MovementKind, from: CarbonImmutable, to: CarbonImmutable, remaining: string, reclaimable: string}>
     */
    private array $lots;

    /**
     * @param  array<int, array{id: int, allocation_id: int|null, kind: MovementKind, from: CarbonImmutable, to: CarbonImmutable, remaining: string, reclaimable: string}>  $lots
     */
    public function __construct(array $lots)
    {
        uasort($lots, function (array $a, array $b): int {
            return [$a['to']->getTimestamp(), $a['from']->getTimestamp(), $a['id']]
                <=> [$b['to']->getTimestamp(), $b['from']->getTimestamp(), $b['id']];
        });
        $this->lots = $lots;
    }

    /**
     * Load the lots of live (submitted) allocations.
     *
     * @param  list<MovementKind>|null  $kinds  restrict to these credit kinds
     */
    public static function load(int $employeeId, int $leaveTypeId, ?array $kinds = null, ?int $allocationId = null): self
    {
        $ledger = app(LeaveLedger::class);

        $query = LeaveMovement::query()->lots()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereHas('allocation', fn ($q) => $q->where('status', DocumentStatus::Submitted->value));
        if ($kinds !== null) {
            $query->whereIn('kind', array_map(fn (MovementKind $k) => $k->value, $kinds));
        }
        if ($allocationId !== null) {
            $query->where('leave_allocation_id', $allocationId);
        }

        $rows = $query->get();
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $remaining = $ledger->remainingMany($ids);
        $expired = $ledger->expiredMany($ids);

        $lots = [];
        foreach ($rows as $row) {
            $lots[$row->id] = [
                'id' => $row->id,
                'allocation_id' => $row->leave_allocation_id,
                'kind' => $row->kind,
                'from' => $row->date_from,
                'to' => $row->date_to,
                'remaining' => $remaining[$row->id] ?? (string) $row->days,
                'reclaimable' => Dec::max($expired[$row->id] ?? '0', '0'),
            ];
        }

        return new self($lots);
    }

    /**
     * @return array<int, array{id: int, allocation_id: int|null, kind: MovementKind, from: CarbonImmutable, to: CarbonImmutable, remaining: string, reclaimable: string}>
     */
    public function lots(): array
    {
        return $this->lots;
    }

    /** Σ of negative remainders (how far the balance already went below zero). */
    public function negativeTotal(): string
    {
        $sum = '0';
        foreach ($this->lots as $lot) {
            if (Dec::isNegative($lot['remaining'])) {
                $sum = Dec::add($sum, Dec::neg($lot['remaining']));
            }
        }

        return $sum;
    }

    /** Σ remaining (> 0) of lots usable on a date. */
    public function availableOn(CarbonImmutable $date): string
    {
        $sum = '0';
        foreach ($this->lots as $lot) {
            if ($date->betweenIncluded($lot['from'], $lot['to']) && Dec::isPositive($lot['remaining'])) {
                $sum = Dec::add($sum, $lot['remaining']);
            }
        }

        return $sum;
    }

    /**
     * Consume days from the pool (mutates it) and return the plan.
     *
     * @param  iterable<array{date: CarbonImmutable, portion: string}>  $days
     */
    public function consume(iterable $days, bool $allowReclaim = true): Consumption
    {
        $plan = new Consumption;

        foreach ($days as $day) {
            $date = $day['date'];
            $need = Dec::norm($day['portion']);
            if (! Dec::isPositive($need)) {
                continue;
            }

            foreach ($this->lots as $id => $lot) {
                if (! Dec::isPositive($need)) {
                    break;
                }
                if (! $date->betweenIncluded($lot['from'], $lot['to'])) {
                    continue;
                }

                $take = Dec::min($need, Dec::max($lot['remaining'], '0'));
                if (Dec::isPositive($take)) {
                    $this->lots[$id]['remaining'] = Dec::sub($lot['remaining'], $take);
                    $plan->take($lot, $date, $take, '0');
                    $need = Dec::sub($need, $take);
                }

                if ($allowReclaim && Dec::isPositive($need) && Dec::isPositive($this->lots[$id]['reclaimable'])) {
                    $reclaim = Dec::min($need, $this->lots[$id]['reclaimable']);
                    $this->lots[$id]['reclaimable'] = Dec::sub($this->lots[$id]['reclaimable'], $reclaim);
                    $plan->take($lot, $date, $reclaim, $reclaim);
                    $need = Dec::sub($need, $reclaim);
                }
            }

            if (Dec::isPositive($need)) {
                $plan->unmet($date, $need);
            }
        }

        return $plan;
    }
}
