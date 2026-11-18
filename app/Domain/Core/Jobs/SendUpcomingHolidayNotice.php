<?php

declare(strict_types=1);

namespace App\Domain\Core\Jobs;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Enums\HolidayNoticeFrequency;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Notifications\CoreDigestNotification;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Shared\Models\ReminderLog;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainError;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Weekly, Monday 07:10 (B7): when enabled per organisation, each active employee
 * with a login gets the coming week's (or, on the first Monday, the coming
 * month's) holidays from their resolved list. Weekly offs are not listed.
 */
final class SendUpcomingHolidayNotice implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(HolidayResolver $resolver): void
    {
        $today = today()->toImmutable();

        foreach (Organisation::query()->where('is_active', true)->get() as $organisation) {
            OrganisationContext::run($organisation, function () use ($organisation, $today, $resolver): void {
                $setting = HrSettings::current()->holiday_notice;
                if ($setting === HolidayNoticeFrequency::Off) {
                    return;
                }
                if ($setting === HolidayNoticeFrequency::Monthly && $today->day > 7) {
                    return;
                }

                $to = $setting === HolidayNoticeFrequency::Weekly ? $today->addDays(6) : $today->addMonthNoOverflow()->subDay();

                $employees = Employee::query()->where('status', EmployeeStatus::Active->value)->whereNotNull('user_id')->get();
                foreach ($employees as $employee) {
                    try {
                        $holidays = $resolver->holidaysBetween($employee, $today, $to)
                            ->filter(fn (array $h) => $h['kind'] !== HolidayKind::WeeklyOff->value);
                    } catch (DomainError) {
                        continue;
                    }
                    if ($holidays->isEmpty() || ! ReminderLog::claim($organisation->id, $employee->id, 'holiday_notice', $today)) {
                        continue;
                    }

                    $lines = $holidays->map(fn (array $h, string $date) => [
                        'key' => 'core.notifications.holiday_notice.line',
                        'params' => ['date' => CarbonImmutable::parse($date)->format('d/m/Y'), 'name' => $h['name']],
                    ])->values()->all();

                    User::query()->find($employee->user_id)?->notify(new CoreDigestNotification('holiday_notice', $organisation->name, $lines));
                }
            });
        }
    }
}
