<?php

// spec-02 §7 case 15 + building blocks of §3.2 / §3.6 (no database).

use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Services\EntitlementCalculator;
use App\Domain\Leave\Services\LotPool;
use App\Domain\Leave\Support\Dec;
use Carbon\CarbonImmutable;

// #15
it('rounds half-up to the step', function () {
    expect(Dec::toStep('2.25', '0.5'))->toBe('2.500')
        ->and(Dec::toStep('2.24', '0.5'))->toBe('2.000')
        ->and(Dec::toStep('6.667', '1'))->toBe('7.000')
        ->and(Dec::toStep('14.274', '0.5'))->toBe('14.500')
        ->and(Dec::toStep('1.234', null))->toBe('1.234');
});

it('counts completed months and service months', function () {
    $d = fn (string $s) => CarbonImmutable::parse($s);

    expect(EntitlementCalculator::completedMonths($d('2026-03-16'), $d('2026-12-31')))->toBe(9)
        ->and(EntitlementCalculator::completedMonths($d('2026-03-01'), $d('2026-12-31')))->toBe(10)
        ->and(EntitlementCalculator::completedMonths($d('2026-01-01'), $d('2026-06-30')))->toBe(6)
        ->and(EntitlementCalculator::serviceMonthsOn($d('2024-07-01'), $d('2026-06-30')))->toBe(23)
        ->and(EntitlementCalculator::serviceMonthsOn($d('2024-07-01'), $d('2026-07-01')))->toBe(24);
});

it('consumes soonest-expiring lots first and splits a day across lots', function () {
    $d = fn (string $s) => CarbonImmutable::parse($s);
    $lot = fn (int $id, string $from, string $to, string $remaining, string $reclaimable = '0') => [
        'id' => $id, 'allocation_id' => 1, 'kind' => MovementKind::Allocation, 'from' => $d($from), 'to' => $d($to),
        'remaining' => $remaining, 'reclaimable' => $reclaimable,
    ];

    // carried lot (ends 31/03) has 2.5 left, the year lot has 12
    $pool = new LotPool([1 => $lot(1, '2026-01-01', '2026-12-31', '12'), 2 => $lot(2, '2026-01-01', '2026-03-31', '2.5')]);
    $plan = $pool->consume([
        ['date' => $d('2026-03-30'), 'portion' => '1'],
        ['date' => $d('2026-03-31'), 'portion' => '1'],
        ['date' => $d('2026-04-01'), 'portion' => '1'],
    ]);

    expect($plan->perLot[2]['days'])->toBe('2.000')
        ->and($plan->perLot[1]['days'])->toBe('1.000')
        ->and($plan->shortfall())->toBe('0');

    $pool2 = new LotPool([2 => $lot(2, '2026-01-01', '2026-03-31', '0.5'), 1 => $lot(1, '2026-01-01', '2026-12-31', '5')]);
    $split = $pool2->consume([['date' => $d('2026-03-31'), 'portion' => '1']]);
    expect($split->perLot[2]['days'])->toBe('0.500')->and($split->perLot[1]['days'])->toBe('0.500');

    $short = (new LotPool([1 => $lot(1, '2026-01-01', '2026-12-31', '1')]))->consume([['date' => $d('2026-06-01'), 'portion' => '1'], ['date' => $d('2026-06-02'), 'portion' => '1']]);
    expect($short->shortfall())->toBe('1.000');
});
