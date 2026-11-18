<?php

use App\Domain\Core\Enums\MaritalStatus;
use App\Domain\Core\Services\PcbCategorySuggester;
use App\Domain\Shared\Dating\DateRange;

it('splits a range at boundaries and computes overlaps', function () {
    $range = new DateRange('2026-06-01', '2026-06-30');
    $segments = $range->splitAt(['2026-06-15', '2026-05-01', '2026-07-05', '2026-06-20']);

    expect(array_map(fn (DateRange $s) => $s->toArray(), $segments))->toBe([
        ['from' => '2026-06-01', 'to' => '2026-06-14'],
        ['from' => '2026-06-15', 'to' => '2026-06-19'],
        ['from' => '2026-06-20', 'to' => '2026-06-30'],
    ])
        ->and($range->days())->toBe(30)
        ->and($range->contains('2026-06-30'))->toBeTrue()
        ->and($range->contains('2026-07-01'))->toBeFalse()
        ->and($range->overlaps(new DateRange('2026-06-30', null)))->toBeTrue()
        ->and($range->intersect(new DateRange('2026-06-25', '2026-07-10'))?->toArray())->toBe(['from' => '2026-06-25', 'to' => '2026-06-30']);
});

it('rejects an end before the start', function () {
    expect(fn () => new DateRange('2026-06-02', '2026-06-01'))->toThrow(InvalidArgumentException::class);
});

// B8 #17 (suggestion part)
it('suggests the PCB category from marital status and spouse work', function () {
    $s = new PcbCategorySuggester;

    expect($s->suggest(MaritalStatus::Single, null))->toBe(1)
        ->and($s->suggest(MaritalStatus::Married, false))->toBe(2)
        ->and($s->suggest(MaritalStatus::Married, true))->toBe(3)
        ->and($s->suggest(MaritalStatus::Divorced, null))->toBe(3)
        ->and($s->suggest(MaritalStatus::Widowed, null))->toBe(3);
});
