<?php

declare(strict_types=1);

namespace App\Domain\Leave\Jobs;

use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\PostedVia;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Notifications\LeaveCreditFailed;
use App\Domain\Leave\Services\CreditPoster;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * 00:15 daily (spec-02 §6.1, §3.8): every pending credit due on or before today,
 * oldest first — so missed runs catch up. Failures are reported to HR once per run.
 */
final class PostDueLeaveCredits implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(CreditPoster $poster, HrRecipients $hr): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($poster, $hr, $orgId): void {
                $failed = 0;
                LeaveCreditSchedule::query()
                    ->where('status', CreditScheduleStatus::Pending->value)
                    ->whereDate('due_date', '<=', today()->toDateString())
                    ->orderBy('due_date')->orderBy('id')
                    ->get()
                    ->each(function (LeaveCreditSchedule $row) use ($poster, &$failed): void {
                        if ($poster->post($row, PostedVia::Scheduler) === CreditScheduleStatus::Failed) {
                            $failed++;
                        }
                    });

                if ($failed > 0) {
                    $recipients = $hr->for((int) $orgId);
                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new LeaveCreditFailed($failed));
                    }
                }
            });
        }
    }
}
