<?php

declare(strict_types=1);

namespace App\Domain\Shared\Dating;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rows with effective_from / effective_to (inclusive, null = open) forming
 * non-overlapping series (A9).
 *
 * @mixin Model
 */
trait EffectiveDated
{
    /**
     * Columns and values identifying the series this row belongs to.
     *
     * @return array<string, mixed>
     */
    abstract public function seriesKey(): array;

    /**
     * Whether this row takes part in its series (e.g. only submitted lifecycle rows).
     */
    public function participatesInSeries(): bool
    {
        return true;
    }

    /**
     * Restrict a query to rows that take part in series (override with lifecycle).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeParticipating(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $d = DateRange::day($date)->toDateString();

        return $query->whereDate($this->qualifyColumn('effective_from'), '<=', $d)
            ->where(function (Builder $q) use ($d): void {
                $q->whereNull($this->qualifyColumn('effective_to'))
                    ->orWhereDate($this->qualifyColumn('effective_to'), '>=', $d);
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverlapping(Builder $query, CarbonInterface|string $from, CarbonInterface|string|null $to): Builder
    {
        $f = DateRange::day($from)->toDateString();

        $query->where(function (Builder $q) use ($f): void {
            $q->whereNull($this->qualifyColumn('effective_to'))
                ->orWhereDate($this->qualifyColumn('effective_to'), '>=', $f);
        });

        if ($to !== null) {
            $query->whereDate($this->qualifyColumn('effective_from'), '<=', DateRange::day($to)->toDateString());
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInSeriesOf(Builder $query, Model $row): Builder
    {
        $keys = method_exists($row, 'seriesKey') ? $row->seriesKey() : [];
        foreach ($keys as $column => $value) {
            $query->where($this->qualifyColumn($column), $value);
        }

        return $query;
    }

    public function effectiveRange(): DateRange
    {
        return new DateRange($this->getAttribute('effective_from'), $this->getAttribute('effective_to'));
    }
}
