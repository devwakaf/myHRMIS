<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\LocationConsent;
use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Notis Privasi Lokasi decisions (spec-03 §2.1, §3.14): accept / decline the
 * current notice version, or withdraw (blocks geofenced check-in from that moment).
 */
final class RecordLocationConsent
{
    public function decide(Employee $employee, bool $accepted, ?string $userAgent): LocationConsent
    {
        $settings = AttendanceSettings::current($employee->organisation_id);

        return LocationConsent::query()->create([
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'notice_version' => $settings->location_notice_version,
            'accepted' => $accepted,
            'decided_at' => CarbonImmutable::now(),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);
    }

    public function withdraw(Employee $employee): void
    {
        $latest = LocationConsent::latestFor($employee->id);
        if ($latest !== null && $latest->accepted && $latest->withdrawn_at === null) {
            $latest->forceFill(['withdrawn_at' => CarbonImmutable::now()])->save();
        }
    }
}
