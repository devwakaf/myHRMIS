<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Notifications\ReviewDigest;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Shared\Models\ReminderLog;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * Daily 01:45 (spec-03 §6): HR gets counts per review reason for items older
 * than 24 h — once per day per organisation (reminder_logs).
 */
final class SendAttendanceReviewDigest implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(HrRecipients $hr): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($hr, $orgId): void {
                $counts = EmployeeCheckin::query()->notVoided()
                    ->where('processing_state', ProcessingState::NeedsReview->value)
                    ->where('updated_at', '<=', now()->subDay())
                    ->selectRaw('review_reason, COUNT(*) as total')->groupBy('review_reason')
                    ->pluck('total', 'review_reason')->map(fn ($n) => (int) $n)->all();

                if ($counts === [] || ! ReminderLog::claim((int) $orgId, null, 'attendance_review_digest', today())) {
                    return;
                }

                $recipients = $hr->for((int) $orgId);
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new ReviewDigest($counts));
                }
            });
        }
    }
}
