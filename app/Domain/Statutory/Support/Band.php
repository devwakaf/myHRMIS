<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Exceptions\StatutoryRateException;

/**
 * One verbatim table row: wage_from <= W <= wage_to gives fixed amounts.
 */
final class Band
{
    /**
     * @param  array<string, string|null>  $amounts
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $amounts,
    ) {}

    public function contains(string $wage): bool
    {
        return Num::cmp($wage, $this->from) >= 0 && Num::cmp($wage, $this->to) <= 0;
    }

    /** Amount of a column; a blank cell is an incomplete table, never silently zero. */
    public function amount(string $column, string $scheme): string
    {
        $value = $this->amounts[$column] ?? null;
        if ($value === null) {
            throw new StatutoryRateException('statutory.errors.band_incomplete', [
                'scheme' => $scheme, 'from' => $this->from, 'to' => $this->to, 'column' => $column,
            ]);
        }

        return $value;
    }

    /**
     * Find the band containing $wage.
     *
     * @param  list<Band>  $bands
     */
    public static function find(array $bands, string $wage): ?self
    {
        foreach ($bands as $band) {
            if ($band->contains($wage)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }
}
