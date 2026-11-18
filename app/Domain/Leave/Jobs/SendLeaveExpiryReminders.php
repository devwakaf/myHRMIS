<?php

declare(strict_types=1);

namespace App\Domain\Leave\Jobs;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Notifications\LeaveExpiringSoon;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Models\ReminderLog;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * 07:15 daily (spec-02 §6.1): lots with a positive remainder expiring within
 * `expiry_reminder_days` → LeaveExpiringSoon to the Kakitangan, once per lot.
 */
final class SendLeaveExpiryReminders implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(LeaveLedger $ledger): void
    {
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($ledger, $orgId): void {
                $days = LeaveSettings::current((int) $orgId)->expiry_reminder_days;
                $today = today();
                $lots = LeaveMovement::query()->lots()
                    ->whereDate('date_to', '>=', $today->toDateString())
                    ->whereDate('date_to', '<=', $today->addDays($days)->toDateString())
                    ->get();
                $remaining = $ledger->remainingMany($lots->pluck('id')->map(fn ($id) => (int) $id)->all());

                foreach ($lots as $lot) {
                    $left = $remaining[$lot->id] ?? '0';
                    if (! Dec::isPositive($left)) {
                        continue;
                    }
                    if (! ReminderLog::claim((int) $orgId, $lot->employee_id, 'leave_expiry', $lot->date_to, 'lot:'.$lot->id)) {
                        continue;
                    }
                    $employee = Employee::query()->with('user')->find($lot->employee_id);
                    $type = LeaveType::query()->find($lot->leave_type_id);
                    if ($employee?->user === null || $type === null) {
                        continue;
                    }
                    $employee->user->notify(new LeaveExpiringSoon($type->displayName(), Dec::short($left), $lot->date_to->format('d/m/Y')));
                }
            });
        }
    }
}
