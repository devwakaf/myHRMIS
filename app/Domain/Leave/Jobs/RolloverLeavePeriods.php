<?php

declare(strict_types=1);

namespace App\Domain\Leave\Jobs;

use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Actions\AllocationActions;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Services\CarryForwardProcessor;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * 00:10 daily (spec-02 §6.1): periods that have started and were not generated →
 * generate allocations (idempotent), then carry-forward every started allocation
 * not yet processed (§3.11).
 */
final class RolloverLeavePeriods implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(AllocationActions $allocations, CarryForwardProcessor $carry): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($allocations, $carry): void {
                $today = today()->toDateString();

                LeavePeriod::query()->whereNull('allocations_generated_at')->whereDate('from_date', '<=', $today)
                    ->orderBy('from_date')->get()
                    ->each(fn (LeavePeriod $p) => $allocations->generateForPeriod($p));

                LeaveAllocation::query()->live()->whereNull('carry_processed_at')->whereDate('from_date', '<=', $today)
                    ->orderBy('from_date')->get()
                    ->each(fn (LeaveAllocation $a) => $carry->process($a));
            });
        }
    }
}
