<?php

declare(strict_types=1);

namespace App\Domain\Core\Jobs;

use App\Domain\Core\Actions\ProcessSeparation;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * 00:40 daily (B7): relieving_date < today and status ≠ left → separate (B2).
 */
final class ProcessDueSeparations implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(ProcessSeparation $separation): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($separation): void {
                Employee::query()
                    ->where('status', '!=', EmployeeStatus::Left->value)
                    ->whereNotNull('relieving_date')
                    ->whereDate('relieving_date', '<', today()->toDateString())
                    ->get()
                    ->each(fn (Employee $e) => $separation->handle($e));
            });
        }
    }
}
