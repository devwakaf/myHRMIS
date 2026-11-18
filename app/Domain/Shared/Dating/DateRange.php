<?php

declare(strict_types=1);

namespace App\Domain\Shared\Dating;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use InvalidArgumentException;

/**
 * Inclusive date range; `to = null` means open-ended.
 */
final class DateRange
{
    public readonly CarbonImmutable $from;

    public readonly ?CarbonImmutable $to;

    public function __construct(CarbonInterface|string $from, CarbonInterface|string|null $to = null)
    {
        $this->from = self::day($from);
        $this->to = $to === null ? null : self::day($to);

        if ($this->to !== null && $this->to->lessThan($this->from)) {
            throw new InvalidArgumentException('Range end is before its start.');
        }
    }

    public static function day(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date instanceof CarbonInterface ? $date->format('Y-m-d') : $date)->startOfDay();
    }

    public function contains(CarbonInterface|string $date): bool
    {
        $d = self::day($date);

        return $d->greaterThanOrEqualTo($this->from) && ($this->to === null || $d->lessThanOrEqualTo($this->to));
    }

    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = $other->to === null || $this->from->lessThanOrEqualTo($other->to);
        $endsAfterOtherStarts = $this->to === null || $this->to->greaterThanOrEqualTo($other->from);

        return $startsBeforeOtherEnds && $endsAfterOtherStarts;
    }

    public function intersect(self $other): ?self
    {
        if (! $this->overlaps($other)) {
            return null;
        }

        $from = $this->from->max($other->from);
        $to = match (true) {
            $this->to === null => $other->to,
            $other->to === null => $this->to,
            default => $this->to->min($other->to),
        };

        return new self($from, $to);
    }

    /** Number of days, inclusive. Requires a closed range. */
    public function days(): int
    {
        if ($this->to === null) {
            throw new InvalidArgumentException('Open-ended range has no day count.');
        }

        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * @return Generator<int, CarbonImmutable>
     */
    public function dates(): Generator
    {
        if ($this->to === null) {
            throw new InvalidArgumentException('Cannot iterate an open-ended range.');
        }

        for ($d = $this->from; $d->lessThanOrEqualTo($this->to); $d = $d->addDay()) {
            yield $d;
        }
    }

    /**
     * Split this range at the given start dates (each boundary starts a new segment).
     *
     * @param  iterable<CarbonInterface|string>  $boundaries
     * @return list<self>
     */
    public function splitAt(iterable $boundaries): array
    {
        $points = [];
        foreach ($boundaries as $b) {
            $d = self::day($b);
            if ($d->greaterThan($this->from) && ($this->to === null || $d->lessThanOrEqualTo($this->to))) {
                $points[$d->format('Y-m-d')] = $d;
            }
        }
        ksort($points);

        $segments = [];
        $start = $this->from;
        foreach ($points as $point) {
            $segments[] = new self($start, $point->subDay());
            $start = $point;
        }
        $segments[] = new self($start, $this->to);

        return $segments;
    }

    /**
     * @return array{from: string, to: string|null}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to?->toDateString()];
    }
}
