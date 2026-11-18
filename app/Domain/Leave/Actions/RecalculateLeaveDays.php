<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveReviewTask;
use App\Domain\Leave\Notifications\LeaveRecheckNeeded;
use App\Domain\Leave\Services\LeaveDayCalculator;
use App\Domain\Leave\Support\DayBreakdown;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Holiday changes (spec-02 §3.14, the A5.4 exception). Pending applications get
 * their day rows and computed totals rewritten under the named lock bypass;
 * approved ones are refunded (reverse + re-post) when the count drops, and sent
 * to HR's "Permohonan Perlu Semakan" queue when it rises. Frozen approval
 * attributes are never touched.
 */
final class RecalculateLeaveDays
{
    public function __construct(
        private readonly LeaveDayCalculator $calculator,
        private readonly SubmitLeaveApplication $submitter,
        private readonly PostApprovedLeave $post,
        private readonly ReverseApprovedLeave $reverse,
        private readonly LeaveAttendancePort $attendance,
        private readonly HolidayResolver $holidays,
    ) {}

    /**
     * @return string outcome: unchanged | recalculated | refunded | review
     */
    public function handle(LeaveApplication $application, string $trigger): string
    {
        $this->holidays->flush();

        return DB::transaction(function () use ($application, $trigger): string {
            /** @var LeaveApplication $application */
            $application = LeaveApplication::query()->lockForUpdate()->with('leaveType')->findOrFail($application->id);
            if (! in_array($application->status, [ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Draft], true)) {
                return 'unchanged';
            }

            $employee = Employee::query()->findOrFail($application->employee_id);
            try {
                $breakdown = $this->calculator->build($employee, $application->leaveType, $application->from_date, $application->to_date, $application->half_day_date, $application->half_day_session);
            } catch (ValidationException $e) {
                $this->review($application, 'recalculation_failed', ['message' => array_values($e->errors())[0][0] ?? '']);

                return 'review';
            }

            $old = (string) $application->total_days;
            $new = $breakdown->totalDays;
            if (Dec::cmp($old, $new) === 0 && Dec::cmp((string) $application->unpaid_days, $breakdown->unpaidDays) === 0) {
                return 'unchanged';
            }

            if ($application->status === ApprovalStatus::Approved && Dec::cmp($new, $old) > 0) {
                $this->review($application, 'holiday_removed', ['old_days' => $old, 'new_days' => $new, 'trigger' => $trigger]);

                return 'review';
            }

            $this->rewrite($application, $breakdown, $trigger);

            if ($application->status === ApprovalStatus::Approved) {
                $this->reverse->handle($application, unmarkAttendance: false);
                $this->post->handle($application->refresh(), markAttendance: false);
                $this->attendance->unmarkLeave($application);
                $this->attendance->markLeave($application);

                return 'refunded';
            }

            return 'recalculated';
        });
    }

    private function rewrite(LeaveApplication $application, DayBreakdown $breakdown, string $trigger): void
    {
        $values = [
            'total_days' => $breakdown->totalDays,
            'unpaid_days' => $breakdown->unpaidDays,
            'calendar_days' => $breakdown->calendarDays,
        ];

        if ($application->isDraft()) {
            $application->forceFill($values)->save();
        } else {
            LeaveApplication::withLockBypass(LeaveApplication::LOCK_BYPASS_HOLIDAY, function () use ($application, $values): void {
                $application->forceFill($values)->save();
            });
            activity('leave')->performedOn($application)->event('holiday_recalculated')
                ->withProperties(['trigger' => $trigger])->log('holiday_recalculated');
        }

        $this->submitter->storeDays($application, $breakdown);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function review(LeaveApplication $application, string $reason, array $details): void
    {
        $task = LeaveReviewTask::query()->firstOrCreate(
            ['leave_application_id' => $application->id, 'reason' => $reason, 'resolved_at' => null],
            ['organisation_id' => $application->organisation_id, 'details' => $details],
        );

        if ($task->wasRecentlyCreated) {
            $orgId = $application->organisation_id;
            $taskId = $task->id;
            DB::afterCommit(function () use ($orgId, $taskId): void {
                $recipients = app(HrRecipients::class)->for($orgId);
                $task = LeaveReviewTask::query()->withoutGlobalScopes()->find($taskId);
                if ($recipients->isNotEmpty() && $task !== null) {
                    Notification::send($recipients, new LeaveRecheckNeeded($task));
                }
            });
        }
    }
}
