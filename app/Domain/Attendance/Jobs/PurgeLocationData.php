<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Jobs;

use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\CheckinAttemptLog;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Daily 03:00 (spec-03 §6, PDPA retention): coordinates, IP and user agent of
 * punches older than location_retention_months are nulled (distance and geofence
 * result kept); attempt logs past their retention are deleted.
 */
final class PurgeLocationData implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        foreach (Organisation::query()->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($orgId): void {
                $settings = AttendanceSettings::current((int) $orgId);
                $cutoff = now()->subMonths($settings->location_retention_months);

                EmployeeCheckin::query()->where('punched_at', '<', $cutoff)
                    ->where(fn ($q) => $q->whereNotNull('latitude')->orWhereNotNull('longitude')->orWhereNotNull('ip_address')->orWhereNotNull('user_agent'))
                    ->update(['latitude' => null, 'longitude' => null, 'accuracy_m' => null, 'ip_address' => null, 'user_agent' => null, 'updated_at' => now()]);

                CheckinAttemptLog::query()->where('attempted_at', '<', now()->subDays($settings->attempt_log_retention_days))->delete();
            });
        }
    }
}
