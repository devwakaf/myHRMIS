<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Support\Dec;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Expiry (spec-02 §3.12): every lot that ended before today with a positive
 * remainder gets an `expiry` row for that remainder. Idempotent by construction;
 * debits reversed on an ended lot are re-expired on the next run.
 */
final class LotExpirer
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /** Returns the number of expiry rows written (active organisation only). */
    public function run(CarbonInterface $today): int
    {
        $lotIds = LeaveMovement::query()->lots()
            ->whereDate('date_to', '<', $today->toDateString())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $count = 0;
        foreach (array_chunk($lotIds, 500) as $chunk) {
            $remaining = $this->ledger->remainingMany($chunk);
            foreach ($remaining as $lotId => $left) {
                if (! Dec::isPositive($left)) {
                    continue;
                }
                DB::transaction(function () use ($lotId, $today, &$count): void {
                    $lot = LeaveMovement::query()->lockForUpdate()->findOrFail($lotId);
                    if ($this->ledger->expire($lot, $today) !== null) {
                        $count++;
                    }
                });
            }
        }

        return $count;
    }
}
