<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of Ledger Cuti rows (spec-02 §2.6). Rows are append-only:
 * credits create lots, debits name the lot they consume, corrections are
 * reversal rows (`days = −original`, `reverses_movement_id` unique).
 */
final class LeaveLedger
{
    /** Post a credit (a new lot) in an allocation. */
    public function credit(
        LeaveAllocation $allocation,
        MovementKind $kind,
        string $days,
        CarbonInterface $validFrom,
        CarbonInterface $validTo,
        CarbonInterface $postedOn,
        ?Model $source = null,
        ?string $note = null,
    ): LeaveMovement {
        if (! $kind->isCredit()) {
            throw new InvalidArgumentException("{$kind->value} is not a credit kind.");
        }
        if (Dec::isNegative($days) || ($kind !== MovementKind::Allocation && ! Dec::isPositive($days))) {
            throw new InvalidArgumentException('Credit days must be positive (allocation lots may be 0).');
        }

        return LeaveMovement::query()->create([
            'organisation_id' => $allocation->organisation_id,
            'employee_id' => $allocation->employee_id,
            'leave_type_id' => $allocation->leave_type_id,
            'leave_allocation_id' => $allocation->id,
            'kind' => $kind,
            'days' => Dec::norm($days),
            'lot_id' => null,
            'date_from' => DateRange::day($validFrom)->toDateString(),
            'date_to' => DateRange::day($validTo)->toDateString(),
            'posted_on' => DateRange::day($postedOn)->toDateString(),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'note' => $note,
        ]);
    }

    /**
     * Post a debit of $days (positive number) against a lot, or a no-balance debit when $lot is null.
     *
     * @param  array{employee_id: int, leave_type_id: int, organisation_id: int}|null  $owner  required when $lot is null
     */
    public function debit(
        ?LeaveMovement $lot,
        MovementKind $kind,
        string $days,
        CarbonInterface $dateFrom,
        CarbonInterface $dateTo,
        CarbonInterface $postedOn,
        ?Model $source = null,
        string $unpaidDays = '0',
        ?string $note = null,
        ?array $owner = null,
    ): LeaveMovement {
        if (! Dec::isPositive($days)) {
            throw new InvalidArgumentException('Debit days must be positive.');
        }
        if ($lot === null && $owner === null) {
            throw new InvalidArgumentException('A no-balance debit needs its owner.');
        }

        return LeaveMovement::query()->create([
            'organisation_id' => $lot->organisation_id ?? $owner['organisation_id'] ?? null,
            'employee_id' => $lot->employee_id ?? $owner['employee_id'] ?? null,
            'leave_type_id' => $lot->leave_type_id ?? $owner['leave_type_id'] ?? null,
            'leave_allocation_id' => $lot?->leave_allocation_id,
            'kind' => $kind,
            'days' => Dec::neg($days),
            'lot_id' => $lot?->id,
            'date_from' => DateRange::day($dateFrom)->toDateString(),
            'date_to' => DateRange::day($dateTo)->toDateString(),
            'posted_on' => DateRange::day($postedOn)->toDateString(),
            'unpaid_days' => Dec::norm($unpaidDays),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'note' => $note,
        ]);
    }

    /** Reverse a row once (unique key on reverses_movement_id enforces "once"). */
    public function reverse(LeaveMovement $original, CarbonInterface $postedOn, ?Model $source = null, ?string $note = null): LeaveMovement
    {
        return LeaveMovement::query()->create([
            'organisation_id' => $original->organisation_id,
            'employee_id' => $original->employee_id,
            'leave_type_id' => $original->leave_type_id,
            'leave_allocation_id' => $original->leave_allocation_id,
            'kind' => MovementKind::Reversal,
            'days' => Dec::neg($original->days),
            'lot_id' => $original->lot_id ?? $original->id,
            'date_from' => $original->date_from->toDateString(),
            'date_to' => $original->date_to->toDateString(),
            'posted_on' => DateRange::day($postedOn)->toDateString(),
            'unpaid_days' => Dec::neg($original->unpaid_days),
            'source_type' => $source?->getMorphClass() ?? $original->source_type,
            'source_id' => $source?->getKey() ?? $original->source_id,
            'reverses_movement_id' => $original->id,
            'note' => $note,
        ]);
    }

    /**
     * Rows not yet reversed (and not themselves reversals).
     *
     * @param  Builder<LeaveMovement>  $query
     * @return Builder<LeaveMovement>
     */
    public function unreversed(Builder $query): Builder
    {
        return $query->where('kind', '!=', MovementKind::Reversal->value)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))->from('leave_movements as r')->whereColumn('r.reverses_movement_id', 'leave_movements.id');
            });
    }

    /**
     * Reverse every unreversed row matching the query (e.g. all debits of a source).
     *
     * @param  Builder<LeaveMovement>  $query
     * @return Collection<int, LeaveMovement>
     */
    public function reverseAll(Builder $query, CarbonInterface $postedOn, ?Model $source = null, ?string $note = null): Collection
    {
        $rows = $this->unreversed($query)->orderByDesc('id')->get();

        return $rows->map(fn (LeaveMovement $m) => $this->reverse($m, $postedOn, $source, $note));
    }

    public function remaining(LeaveMovement $lot): string
    {
        return $this->remainingMany([$lot->id])[$lot->id] ?? '0';
    }

    /**
     * Lot remaining = credit days + Σ days of every row naming the lot.
     *
     * @param  list<int>  $lotIds
     * @return array<int, string>
     */
    public function remainingMany(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        $base = LeaveMovement::query()->withoutGlobalScopes()->whereIn('id', $lotIds)->pluck('days', 'id');
        $children = LeaveMovement::query()->withoutGlobalScopes()->whereIn('lot_id', $lotIds)
            ->groupBy('lot_id')->selectRaw('lot_id, SUM(days) as total')->pluck('total', 'lot_id');

        $out = [];
        foreach ($base as $id => $days) {
            $out[(int) $id] = Dec::add((string) $days, (string) ($children[$id] ?? '0'));
        }

        return $out;
    }

    /**
     * Days currently expired on each lot (expiry rows net of their reversals), as positive numbers.
     *
     * @param  list<int>  $lotIds
     * @return array<int, string>
     */
    public function expiredMany(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        $rows = LeaveMovement::query()->withoutGlobalScopes()->whereIn('lot_id', $lotIds)
            ->where(function (Builder $q): void {
                $q->where('kind', MovementKind::Expiry->value)
                    ->orWhereIn('reverses_movement_id', LeaveMovement::query()->withoutGlobalScopes()
                        ->select('id')->where('kind', MovementKind::Expiry->value));
            })
            ->get(['lot_id', 'days']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->lot_id] = Dec::sub($out[(int) $row->lot_id] ?? '0', $row->days);
        }

        return $out;
    }

    /** Reverse the active expiry rows of a lot (retro consumption after the lot ended, §3.5 step 2). */
    public function unexpire(LeaveMovement $lot, CarbonInterface $postedOn, ?Model $source = null): void
    {
        $this->reverseAll(
            LeaveMovement::query()->where('lot_id', $lot->id)->where('kind', MovementKind::Expiry->value),
            $postedOn,
            $source,
        );
    }

    /** Expire whatever is left on an ended lot (§3.12). Returns the row or null when nothing remains. */
    public function expire(LeaveMovement $lot, CarbonInterface $postedOn, ?Model $source = null): ?LeaveMovement
    {
        $remaining = $this->remaining($lot);
        if (! Dec::isPositive($remaining)) {
            return null;
        }

        return $this->debit($lot, MovementKind::Expiry, $remaining, $lot->date_to, $lot->date_to, $postedOn, $source);
    }

    /** The allocation's main lot (kind `allocation`), target of negative balances. */
    public function mainLot(LeaveAllocation $allocation): ?LeaveMovement
    {
        return LeaveMovement::query()->where('leave_allocation_id', $allocation->id)
            ->where('kind', MovementKind::Allocation->value)->whereNull('lot_id')->whereNull('reverses_movement_id')
            ->orderBy('id')->first();
    }

    /** Net entitlement credited to an allocation (allocation/accrual/tier/policy rows incl. their reversals). */
    public function creditedEntitlement(LeaveAllocation $allocation): string
    {
        $kinds = array_map(fn (MovementKind $k) => $k->value, MovementKind::entitlementCredits());

        $direct = LeaveMovement::query()->where('leave_allocation_id', $allocation->id)->whereIn('kind', $kinds)->sum('days');
        $reversed = LeaveMovement::query()->where('leave_allocation_id', $allocation->id)
            ->where('kind', MovementKind::Reversal->value)
            ->whereIn('reverses_movement_id', LeaveMovement::query()->select('id')
                ->where('leave_allocation_id', $allocation->id)->whereIn('kind', $kinds))
            ->sum('days');

        return Dec::add((string) $direct, (string) $reversed);
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }
}
