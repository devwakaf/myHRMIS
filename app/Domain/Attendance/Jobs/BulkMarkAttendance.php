<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Actions\MarkAttendanceManually;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Tanda Kehadiran Pukal above the row threshold (spec-03 §4.10): runs the same
 * per-row-isolated marking and stores the summary for the result page (7 days).
 */
final class BulkMarkAttendance implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * @param  list<int>  $employeeIds
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $organisationId,
        public readonly array $employeeIds,
        public readonly string $from,
        public readonly string $to,
        public readonly array $data,
        public readonly ?int $actorId,
        public readonly string $token,
    ) {}

    public function handle(MarkAttendanceManually $action): void
    {
        OrganisationContext::run($this->organisationId, function () use ($action): void {
            $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
            $summary = $action->bulk($this->employeeIds, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to), $this->data, $actor, false);
            Cache::put('attendance-bulk:'.$this->token, $summary + ['finished_at' => now()->toIso8601String()], now()->addDays(7));
        });
    }
}
