<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Notifications\MissingCheckinReminder;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Shared\Models\ReminderLog;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Optional, every 15 min (off by default, spec-03 §6): employees whose shift
 * started at least 30 minutes ago with no punch get one reminder per day.
 */
final class RemindMissingCheckin implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const AFTER_MINUTES = 30;

    public function handle(ShiftCalendar $calendar, DayTypeResolver $dayTypes, LeaveQuery $leave): void
    {
        $now = CarbonImmutable::now();
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($calendar, $dayTypes, $leave, $now, $orgId): void {
                if (! AttendanceSettings::current((int) $orgId)->remind_missing_checkin) {
                    return;
                }
                foreach (Employee::query()->active()->whereNotNull('user_id')->with('user')->get() as $employee) {
                    $occurrence = $calendar->occurrenceOn($employee, $now);
                    if ($occurrence === null || $now->lessThan($occurrence->start->addMinutes(self::AFTER_MINUTES)) || $now->greaterThan($occurrence->end)) {
                        continue;
                    }
                    if (! $dayTypes->for($employee, $now, $occurrence->shift)->isNormal() || $leave->approvedLeaveOn($employee, $now) !== null) {
                        continue;
                    }
                    $punched = EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
                        ->where('punched_at', '>=', $occurrence->windowStart)->exists();
                    if ($punched || ! ReminderLog::claim((int) $orgId, $employee->id, 'missing_checkin', $now)) {
                        continue;
                    }
                    $employee->user?->notify(new MissingCheckinReminder($occurrence->shift->name.' '.$occurrence->shift->timeLabel()));
                }
            });
        }
    }
}
