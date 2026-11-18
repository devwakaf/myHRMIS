<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Jobs;

use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\StatutoryRemittance;
use App\Domain\Payroll\Notifications\StatutoryDueReminder;
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
 * Daily 08:00 (spec-04 §6): KWSP, PERKESO, PCB and (when registered) HRD Corp payments of the
 * previous month fall due on the 15th. On the 10th Payroll Officer and Finance are warned about
 * every scheme without a recorded payment (statutory_remittances), and again on the 15th. Only
 * months with a submitted Proses Gaji are checked. Once per day per Organisasi (reminder_logs).
 * The weekend roll of the due date is UNVERIFIED-STAT and not applied.
 */
final class RemindStatutoryDue implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const WARN_DAY = 10;

    public const DUE_DAY = 15;

    public function __construct(public readonly ?CarbonImmutable $today = null) {}

    public function handle(OrganisationMembership $membership): void
    {
        $today = $this->today ?? CarbonImmutable::today();
        if (! in_array($today->day, [self::WARN_DAY, self::DUE_DAY], true)) {
            return;
        }
        $period = $today->startOfMonth()->subMonth();

        foreach (Organisation::query()->where('is_active', true)->get() as $organisation) {
            OrganisationContext::run((int) $organisation->id, function () use ($organisation, $membership, $today, $period): void {
                $ran = PayrollRun::query()->whereDate('period', $period->toDateString())
                    ->whereIn('status', [PayrollRunStatus::Submitted->value, PayrollRunStatus::Paid->value])->exists();
                if (! $ran) {
                    return;
                }
                $schemes = ['kwsp', 'perkeso', 'pcb'];
                if ($organisation->hrd_corp_registered) {
                    $schemes[] = 'hrdc';
                }
                $paid = StatutoryRemittance::query()->whereDate('period', $period->toDateString())->get()->map(fn ($r) => $r->scheme->value)->all();
                $missing = array_values(array_diff($schemes, $paid));
                if ($missing === [] || ! ReminderLog::claim((int) $organisation->id, null, 'statutory_due', $today, $period->format('Y-m'))) {
                    return;
                }

                $ids = array_unique([
                    ...$membership->usersWithRole((int) $organisation->id, PermissionMatrix::PEGAWAI_PAYROLL),
                    ...$membership->usersWithRole((int) $organisation->id, PermissionMatrix::KEWANGAN),
                ]);
                $recipients = User::query()->whereIn('id', $ids)->where('is_active', true)->get();
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new StatutoryDueReminder($period->format('Y-m'), $missing, $today->day === self::DUE_DAY));
                }
            });
        }
    }
}
