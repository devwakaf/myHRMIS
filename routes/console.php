<?php

use App\Domain\Attendance\Jobs\ExtendRosters;
use App\Domain\Attendance\Jobs\GenerateOvertimeClaims;
use App\Domain\Attendance\Jobs\MarkAbsences;
use App\Domain\Attendance\Jobs\ProcessAttendanceCutoff;
use App\Domain\Attendance\Jobs\PurgeLocationData;
use App\Domain\Attendance\Jobs\RemindMissingCheckin;
use App\Domain\Attendance\Jobs\SendAttendanceReviewDigest;
use App\Domain\Core\Jobs\ApplyDueEmployeeMovements;
use App\Domain\Core\Jobs\ProcessDueSeparations;
use App\Domain\Core\Jobs\SendEmploymentReminders;
use App\Domain\Core\Jobs\SendPeopleDigest;
use App\Domain\Core\Jobs\SendUpcomingHolidayNotice;
use App\Domain\Leave\Jobs\ExpireLeaveLots;
use App\Domain\Leave\Jobs\PostDueLeaveCredits;
use App\Domain\Leave\Jobs\RolloverLeavePeriods;
use App\Domain\Leave\Jobs\SendLeaveExpiryReminders;
use App\Domain\Payroll\Jobs\RemindPayrollNotStarted;
use App\Domain\Payroll\Jobs\RemindStatutoryDue;
use App\Domain\Shared\Approval\Jobs\SendApprovalReminders;
use App\Domain\Shared\Approval\Jobs\SyncApproverRoles;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler (spec-00 A10 / B7). All times Asia/Kuala_Lumpur (APP_TIMEZONE).
| Dev: `php artisan schedule:work`. Production (Linux): cron runs `schedule:run`
| every minute. Each job iterates active organisations and is idempotent.
| Attendance / Payroll jobs were added by M3–M4.
*/

// Cuti (spec-02 §6.1): rollover + carry-forward, due credits (catch-up), expiry after carry, reminders.
Schedule::job(new RolloverLeavePeriods)->dailyAt('00:10')->name('leave:rollover-periods')->withoutOverlapping();
Schedule::job(new PostDueLeaveCredits)->dailyAt('00:15')->name('leave:post-due-credits')->withoutOverlapping();
Schedule::job(new ExpireLeaveLots)->dailyAt('00:20')->name('leave:expire-lots')->withoutOverlapping();

Schedule::job(new ApplyDueEmployeeMovements)->dailyAt('00:30')->name('core:apply-due-movements')->withoutOverlapping();
Schedule::job(new ProcessDueSeparations)->dailyAt('00:40')->name('core:process-due-separations')->withoutOverlapping();
Schedule::job(new SyncApproverRoles)->dailyAt('01:00')->name('approval:sync-approver-roles')->withoutOverlapping();
Schedule::job(new SendPeopleDigest)->dailyAt('07:00')->name('core:people-digest')->withoutOverlapping();
Schedule::job(new SendEmploymentReminders)->dailyAt('07:05')->name('core:employment-reminders')->withoutOverlapping();
Schedule::job(new SendUpcomingHolidayNotice)->weeklyOn(1, '07:10')->name('core:upcoming-holiday-notice')->withoutOverlapping();
Schedule::job(new SendLeaveExpiryReminders)->dailyAt('07:15')->name('leave:expiry-reminders')->withoutOverlapping();
Schedule::job(new SendApprovalReminders)->dailyAt('08:00')->name('approval:reminders')->withoutOverlapping();

// Kehadiran & Shift (spec-03 §6). Each job iterates active organisations inside OrganisationContext.
Schedule::job(new ProcessAttendanceCutoff)->everyFifteenMinutes()->name('attendance:process-cutoff')->withoutOverlapping()->onOneServer();
Schedule::job(new ExtendRosters)->dailyAt('00:15')->name('attendance:extend-rosters')->withoutOverlapping()->onOneServer();
Schedule::job(new MarkAbsences)->dailyAt('01:30')->name('attendance:mark-absences')->withoutOverlapping()->onOneServer();
Schedule::job(new SendAttendanceReviewDigest)->dailyAt('01:45')->name('attendance:review-digest')->withoutOverlapping()->onOneServer();
Schedule::job(new PurgeLocationData)->dailyAt('03:00')->name('attendance:purge-location-data')->withoutOverlapping()->onOneServer();
Schedule::job(new GenerateOvertimeClaims)->monthlyOn(1, '04:00')->name('attendance:generate-ot-claims')->withoutOverlapping()->onOneServer();
// Optional reminder: the job itself checks attendance_settings.remind_missing_checkin (off by default).
Schedule::job(new RemindMissingCheckin)->everyFifteenMinutes()->name('attendance:remind-missing-checkin')->withoutOverlapping()->onOneServer();

// Payroll & Statutori (spec-04 §6): reminders only; calculation never runs on a schedule.
Schedule::job(new RemindStatutoryDue)->dailyAt('08:00')->name('payroll:remind-statutory-due')->withoutOverlapping()->onOneServer();
Schedule::job(new RemindPayrollNotStarted)->dailyAt('08:05')->name('payroll:remind-not-started')->withoutOverlapping()->onOneServer();
