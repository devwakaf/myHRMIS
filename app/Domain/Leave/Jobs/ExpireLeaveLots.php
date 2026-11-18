<?php

declare(strict_types=1);

namespace App\Domain\Leave\Jobs;

use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Services\LotExpirer;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * 00:20 daily (spec-02 §6.1, §3.12) — after carry-forward, so unused leave is
 * carried before it expires.
 */
final class ExpireLeaveLots implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(LotExpirer $expirer): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, fn () => $expirer->run(today()));
        }
    }
}
