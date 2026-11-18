<?php

declare(strict_types=1);

namespace App\Domain\Core\Jobs;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Notifications\CoreDigestNotification;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Shared\Models\ReminderLog;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * 07:00 daily (B7): today's birthdays (name only — never year or age) and work
 * anniversaries (from continuous_service_from, else date_of_joining). HR always
 * per settings; colleagues only when enabled (off by default for privacy).
 */
final class SendPeopleDigest implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(HrRecipients $hr): void
    {
        $today = today()->toImmutable();

        foreach (Organisation::query()->where('is_active', true)->get() as $organisation) {
            OrganisationContext::run($organisation, function () use ($organisation, $today, $hr): void {
                if (! ReminderLog::claim($organisation->id, null, 'people_digest', $today)) {
                    return;
                }

                $settings = HrSettings::current();
                $employees = Employee::query()->where('status', EmployeeStatus::Active->value)->get();

                $birthdays = $employees->filter(fn (Employee $e) => $e->date_of_birth->format('m-d') === $today->format('m-d'));
                $anniversaries = $employees->filter(function (Employee $e) use ($today): bool {
                    $start = $e->serviceStart();

                    return $start->format('m-d') === $today->format('m-d') && $start->year < $today->year;
                });

                $lines = [];
                foreach ($birthdays as $e) {
                    $lines[] = ['key' => 'core.notifications.people_digest.birthday', 'params' => ['name' => $e->displayName()]];
                }
                $anniversaryLines = [];
                foreach ($anniversaries as $e) {
                    $anniversaryLines[] = ['key' => 'core.notifications.people_digest.anniversary', 'params' => [
                        'name' => $e->displayName(),
                        'years' => (string) ($today->year - $e->serviceStart()->year),
                    ]];
                }

                $hrLines = array_merge($settings->birthday_digest_to_hr ? $lines : [], $settings->anniversary_digest ? $anniversaryLines : []);
                $hrUsers = $hr->for($organisation->id);
                if ($hrLines !== [] && $hrUsers->isNotEmpty()) {
                    Notification::send($hrUsers, new CoreDigestNotification('people_digest', $organisation->name, $hrLines));
                }

                if ($settings->birthday_digest_to_colleagues && $lines !== []) {
                    $colleagues = User::query()
                        ->whereIn('id', $employees->pluck('user_id')->filter())
                        ->whereNotIn('id', $hrUsers->pluck('id'))
                        ->where('is_active', true)
                        ->get();
                    if ($colleagues->isNotEmpty()) {
                        Notification::send($colleagues, new CoreDigestNotification('people_digest', $organisation->name, $lines));
                    }
                }
            });
        }
    }
}
