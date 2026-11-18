<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Every 15 minutes (spec-03 §4.4, §6): punches whose window closed are turned into
 * attendance. Per-group transactions; failures go to the review queue.
 */
final class ProcessAttendanceCutoff implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly ?int $organisationId = null) {}

    public function handle(AttendanceProcessor $processor): void
    {
        $ids = $this->organisationId !== null ? [$this->organisationId]
            : Organisation::query()->where('is_active', true)->pluck('id')->all();

        foreach ($ids as $orgId) {
            OrganisationContext::run((int) $orgId, fn () => $processor->run());
        }
    }
}
