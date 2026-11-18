<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Contracts\CheckinIngestor;
use App\Domain\Attendance\Enums\AttemptResult;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Enums\LogType;
use App\Domain\Attendance\Exceptions\CheckinRejected;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\CheckinAttemptLog;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\LocationConsent;
use App\Domain\Attendance\Services\GeofenceEvaluator;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Attendance\Support\CheckinOutcome;
use App\Domain\Attendance\Support\GeofenceOutcome;
use App\Domain\Attendance\Support\PunchData;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Web / PWA check-in (spec-03 §4.7). The server clock is authoritative; the
 * device clock is stored for audit only. Idempotent per client_request_id and
 * debounced by min_punch_interval_seconds.
 */
final class RecordWebCheckin implements CheckinIngestor
{
    public const RATE_PER_MINUTE = 10;

    public function __construct(
        private readonly ShiftCalendar $calendar,
        private readonly GeofenceEvaluator $geofence,
    ) {}

    public function ingest(Employee $employee, PunchData $punch): CheckinOutcome
    {
        $now = CarbonImmutable::now();
        $settings = AttendanceSettings::current($employee->organisation_id);
        $profile = EmployeeAttendanceProfile::for($employee);

        if (! EmployeeEligibility::isActiveOn($employee, $now)) {
            throw new CheckinRejected('attendance.checkin.errors.not_active', [], 'not_active');
        }

        if ($punch->clientRequestId !== null) {
            $existing = EmployeeCheckin::query()->where('employee_id', $employee->id)->where('client_request_id', $punch->clientRequestId)->first();
            if ($existing !== null) {
                return new CheckinOutcome($existing, true);
            }
        }

        $rateKey = 'attendance-checkin:'.$employee->id;
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_PER_MINUTE)) {
            $this->logAttempt($employee, AttemptResult::RateLimited, null, $punch->accuracy);
            throw new CheckinRejected('attendance.checkin.errors.rate_limited', [], 'rate_limited', 429);
        }
        RateLimiter::hit($rateKey, 60);

        $geofenceApplies = $settings->geofence_enabled && ! $profile->geofence_exempt;
        $consent = LocationConsent::latestFor($employee->id);
        $consentValid = $consent !== null && $consent->isValidFor($settings->location_notice_version);
        if ($geofenceApplies && ! $consentValid) {
            $this->logAttempt($employee, AttemptResult::NoConsent, null, $punch->accuracy);
            throw new CheckinRejected('attendance.checkin.errors.consent_required', [], 'consent_required', 409, [
                'notice_version' => $settings->location_notice_version,
            ]);
        }

        $recent = EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
            ->where('punched_at', '>', $now->subSeconds($settings->min_punch_interval_seconds))
            ->orderByDesc('punched_at')->first();
        if ($recent !== null) {
            return new CheckinOutcome($recent, true);
        }

        $occurrence = $this->calendar->occurrenceAt($employee, $now);
        $outcome = $this->geofence->evaluate($employee, $occurrence, $punch->latitude, $punch->longitude, $punch->accuracy, $now);
        if (! $outcome->accepted) {
            $this->reject($employee, $outcome, $punch);
        }

        $storeLocation = $consentValid; // coordinates only with a consent in force (PDPA)

        return DB::transaction(function () use ($employee, $punch, $now, $occurrence, $outcome, $storeLocation, $consent): CheckinOutcome {
            $checkin = new EmployeeCheckin;
            $checkin->forceFill(array_merge($occurrence?->snapshot() ?? ShiftOccurrence::emptySnapshot(), [
                'organisation_id' => $employee->organisation_id,
                'employee_id' => $employee->id,
                'punched_at' => $now,
                'received_at' => $now,
                'client_reported_at' => $punch->clientReportedAt,
                'clock_skew_seconds' => $punch->clientReportedAt === null ? null : (int) $now->diffInSeconds($punch->clientReportedAt, false),
                'client_request_id' => $punch->clientRequestId,
                'log_type' => $this->logType($employee, $occurrence),
                'source' => $punch->source,
                'latitude' => $storeLocation ? $punch->latitude : null,
                'longitude' => $storeLocation ? $punch->longitude : null,
                'accuracy_m' => $storeLocation ? $punch->accuracy : null,
                'shift_location_id' => $outcome->location?->id,
                'distance_m' => $outcome->distance === null ? null : round($outcome->distance, 2),
                'geofence_result' => $outcome->result ?? GeofenceResult::NotRequired,
                'ip_address' => $storeLocation ? $punch->ipAddress : null,
                'user_agent' => $storeLocation && $punch->userAgent !== null ? mb_substr($punch->userAgent, 0, 255) : null,
                'location_consent_id' => $storeLocation ? $consent?->id : null,
            ]));
            $checkin->save();

            return new CheckinOutcome($checkin);
        });
    }

    /**
     * @return never
     */
    private function reject(Employee $employee, GeofenceOutcome $outcome, PunchData $punch): void
    {
        $reason = $outcome->rejection ?? AttemptResult::Outside;
        $this->logAttempt($employee, $reason, $outcome, $punch->accuracy);

        $details = [];
        if ($outcome->location !== null && $outcome->distance !== null) {
            $details = ['nearest_location' => $outcome->location->name, 'distance_m' => (int) (round($outcome->distance / 10) * 10)];
        }

        throw new CheckinRejected('attendance.checkin.errors.'.$reason->value, [
            'location' => (string) ($details['nearest_location'] ?? '-'),
            'distance' => (string) ($details['distance_m'] ?? '-'),
        ], $reason->value, 422, $details);
    }

    private function logAttempt(Employee $employee, AttemptResult $result, ?GeofenceOutcome $outcome, ?float $accuracy): void
    {
        CheckinAttemptLog::query()->create([
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'attempted_at' => CarbonImmutable::now(),
            'result' => $result,
            'nearest_location_id' => $outcome?->location?->id,
            'distance_m' => $outcome?->distance === null ? null : (int) (round($outcome->distance / 10) * 10),
            'accuracy_m' => $accuracy,
        ]);
    }

    private function logType(Employee $employee, ?ShiftOccurrence $occurrence): LogType
    {
        if ($occurrence === null) {
            return LogType::In;
        }

        $earlier = EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
            ->where('shift_type_id', $occurrence->shift->id)
            ->whereDate('shift_date', $occurrence->date->toDateString())
            ->exists();

        return $earlier ? LogType::Out : LogType::In;
    }
}
