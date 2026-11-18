<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Jobs;

use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Notifications\PayrollNotStartedReminder;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Models\ReminderLog;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * Daily 08:05 (spec-04 §6): on the configured day of the month (config myhrmis.payroll.not_started_day,
 * default 25) and again on the last day, the Payroll Officers are reminded when the current month has
 * no Proses Gaji yet (cancelled runs do not count). Once per day per Organisasi.
 */
final class RemindPayrollNotStarted implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly ?CarbonImmutable $today = null) {}

    public function handle(OrganisationMembership $membership): void
    {
        $today = $this->today ?? CarbonImmutable::today();
        $day = max(1, min(28, (int) config('myhrmis.payroll.not_started_day', 25)));
        if ($today->day !== $day && ! $today->isSameDay($today->endOfMonth())) {
            return;
        }
        $period = $today->startOfMonth();

        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $organisationId) {
            OrganisationContext::run((int) $organisationId, function () use ($organisationId, $membership, $today, $period): void {
                $started = PayrollRun::query()->whereDate('period', $period->toDateString())->where('status', '!=', PayrollRunStatus::Cancelled->value)->exists();
                if ($started || ! ReminderLog::claim((int) $organisationId, null, 'payroll_not_started', $today, $period->format('Y-m'))) {
                    return;
                }
                $recipients = User::query()->whereIn('id', $membership->usersWithRole((int) $organisationId, PermissionMatrix::PEGAWAI_PAYROLL))->where('is_active', true)->get();
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new PayrollNotStartedReminder($period->format('Y-m')));
                }
            });
        }
    }
}
