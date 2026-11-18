<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Support\Dec;
use Carbon\CarbonImmutable;

/**
 * Plan produced by LotPool::consume(): days per lot (with the first/last date
 * consumed and how much was reclaimed from an expiry), unmet days per date and
 * the allocation that owns each consumed date.
 */
final class Consumption
{
    /** @var array<int, array{lot_id: int, allocation_id: int|null, kind: MovementKind, days: string, reclaimed: string, from: CarbonImmutable, to: CarbonImmutable}> */
    public array $perLot = [];

    /** @var array<string, string> date => unmet days */
    public array $unmet = [];

    /** @var array<string, int|null> date => allocation id of the first lot used */
    public array $allocationByDate = [];

    /**
     * @param  array{id: int, allocation_id: int|null, kind: MovementKind, from: CarbonImmutable, to: CarbonImmutable, remaining: string, reclaimable: string}  $lot
     */
    public function take(array $lot, CarbonImmutable $date, string $days, string $reclaimed): void
    {
        $id = $lot['id'];
        if (! isset($this->perLot[$id])) {
            $this->perLot[$id] = [
                'lot_id' => $id,
                'allocation_id' => $lot['allocation_id'],
                'kind' => $lot['kind'],
                'days' => '0',
                'reclaimed' => '0',
                'from' => $date,
                'to' => $date,
            ];
        }

        $row = &$this->perLot[$id];
        $row['days'] = Dec::add($row['days'], $days);
        $row['reclaimed'] = Dec::add($row['reclaimed'], $reclaimed);
        if ($date->lessThan($row['from'])) {
            $row['from'] = $date;
        }
        if ($date->greaterThan($row['to'])) {
            $row['to'] = $date;
        }
        unset($row);

        $this->allocationByDate[$date->toDateString()] ??= $lot['allocation_id'];
    }

    public function unmet(CarbonImmutable $date, string $days): void
    {
        $key = $date->toDateString();
        $this->unmet[$key] = Dec::add($this->unmet[$key] ?? '0', $days);
    }

    public function shortfall(): string
    {
        return Dec::add(...array_values($this->unmet));
    }

    public function consumed(): string
    {
        return Dec::add(...array_map(fn (array $r) => $r['days'], array_values($this->perLot)));
    }
}
