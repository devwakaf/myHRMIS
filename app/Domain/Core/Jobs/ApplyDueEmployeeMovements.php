<?php

declare(strict_types=1);

namespace App\Domain\Core\Jobs;

use App\Domain\Core\Actions\MovementActions;
use App\Domain\Core\Models\EmployeeMovement;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * 00:30 daily (B7): apply submitted, unapplied movements with effective_date ≤ today.
 */
final class ApplyDueEmployeeMovements implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(MovementActions $movements): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($movements): void {
                EmployeeMovement::query()
                    ->where('status', DocumentStatus::Submitted->value)
                    ->whereNull('applied_at')
                    ->whereDate('effective_date', '<=', today()->toDateString())
                    ->orderBy('effective_date')
                    ->orderBy('id')
                    ->get()
                    ->each(fn (EmployeeMovement $m) => $movements->apply($m));
            });
        }
    }
}
