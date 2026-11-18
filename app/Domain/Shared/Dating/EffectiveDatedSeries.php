<?php

declare(strict_types=1);

namespace App\Domain\Shared\Dating;

use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps an effective-dated series gap-free and non-overlapping (A9 successor rule).
 */
final class EffectiveDatedSeries
{
    /**
     * Insert (or activate) $row in its series: the predecessor is closed at
     * from − 1 and $row ends the day before its successor starts.
     *
     * @param  Model&object  $row  a model using EffectiveDated
     */
    public function insert(Model $row): void
    {
        DB::transaction(function () use ($row): void {
            $from = DateRange::day($row->getAttribute('effective_from'));
            $siblings = $this->siblings($row);

            foreach ($siblings as $sibling) {
                if (DateRange::day($sibling->getAttribute('effective_from'))->equalTo($from)) {
                    throw DomainValidation::withMessages([
                        'effective_from' => __('common.errors.effective_from_taken'),
                    ]);
                }
            }

            $predecessor = $siblings
                ->filter(fn (Model $s) => DateRange::day($s->getAttribute('effective_from'))->lessThan($from))
                ->sortByDesc(fn (Model $s) => DateRange::day($s->getAttribute('effective_from'))->getTimestamp())
                ->first();

            $successor = $siblings
                ->filter(fn (Model $s) => DateRange::day($s->getAttribute('effective_from'))->greaterThan($from))
                ->sortBy(fn (Model $s) => DateRange::day($s->getAttribute('effective_from'))->getTimestamp())
                ->first();

            if ($successor !== null) {
                $row->setAttribute('effective_to', DateRange::day($successor->getAttribute('effective_from'))->subDay()->toDateString());
            }
            $row->save();

            if ($predecessor !== null) {
                $predecessor->setAttribute('effective_to', $from->subDay()->toDateString());
                $predecessor->save();
            }
        });
    }

    /**
     * Take $row out of its series: its predecessor re-opens to $row's end.
     * The caller marks $row as no longer participating (e.g. cancelled) and saves it.
     */
    public function remove(Model $row): void
    {
        DB::transaction(function () use ($row): void {
            $from = DateRange::day($row->getAttribute('effective_from'));

            $predecessor = $this->siblings($row)
                ->filter(fn (Model $s) => DateRange::day($s->getAttribute('effective_from'))->lessThan($from))
                ->sortByDesc(fn (Model $s) => DateRange::day($s->getAttribute('effective_from'))->getTimestamp())
                ->first();

            if ($predecessor !== null) {
                $predecessor->setAttribute('effective_to', $row->getAttribute('effective_to'));
                $predecessor->save();
            }
        });
    }

    /**
     * Participating rows of the same series except $row, locked for update.
     *
     * @return Collection<int, Model>
     */
    private function siblings(Model $row): Collection
    {
        /** @var Builder<Model> $query */
        $query = $row->newQuery();
        $query->scopes(['inSeriesOf' => [$row], 'participating'])->lockForUpdate();

        if ($row->exists) {
            $query->whereKeyNot($row->getKey());
        }

        return $query->get()->toBase();
    }
}
