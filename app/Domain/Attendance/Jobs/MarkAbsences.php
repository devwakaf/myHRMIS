<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Services\AbsenceMarker;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Daily 01:30 (spec-03 §4.5, §6), after Core's 00:30/00:40 jobs: absent marking
 * plus closing pending half days. Idempotent.
 */
final class MarkAbsences implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly ?int $organisationId = null) {}

    public function handle(AbsenceMarker $marker): void
    {
        $ids = $this->organisationId !== null ? [$this->organisationId]
            : Organisation::query()->where('is_active', true)->pluck('id')->all();

        foreach ($ids as $orgId) {
            OrganisationContext::run((int) $orgId, fn () => $marker->run());
        }
    }
}
