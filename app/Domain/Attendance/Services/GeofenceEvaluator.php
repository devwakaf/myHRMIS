<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AttemptResult;
use App\Domain\Attendance\Enums\AttendanceRequestKind;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Enums\LocationLinkType;
use App\Domain\Attendance\Enums\NoLocationPolicy;
use App\Domain\Attendance\Enums\OutsideGeofenceAction;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Support\GeofenceOutcome;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeSnapshot;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Geofence check (spec-03 §4.6): every candidate location of the most specific
 * level is evaluated; the nearest location containing the point wins, subject to
 * its accuracy threshold.
 */
final class GeofenceEvaluator
{
    public const EARTH_RADIUS_M = 6371008.8;

    public function evaluate(Employee $employee, ?ShiftOccurrence $occurrence, ?float $lat, ?float $lng, ?float $accuracy, ?CarbonImmutable $at = null): GeofenceOutcome
    {
        $settings = AttendanceSettings::current($employee->organisation_id);
        $at ??= CarbonImmutable::now();

        if (! $settings->geofence_enabled) {
            return GeofenceOutcome::accept(GeofenceResult::NotRequired);
        }
        if (EmployeeAttendanceProfile::for($employee)->geofence_exempt) {
            return GeofenceOutcome::accept(GeofenceResult::Exempt);
        }
        if ($this->onDutyToday($employee, $at)) {
            return GeofenceOutcome::accept(GeofenceResult::OnDutyBypass);
        }

        $candidates = $this->candidates($employee, $occurrence, $at);
        if ($candidates->isEmpty()) {
            return $settings->no_location_policy === NoLocationPolicy::Allow
                ? GeofenceOutcome::accept(GeofenceResult::NotRequired)
                : GeofenceOutcome::reject(AttemptResult::NoLocation);
        }

        if ($lat === null || $lng === null || $accuracy === null) {
            return GeofenceOutcome::reject(AttemptResult::LowAccuracy);
        }

        $measured = $candidates
            ->map(fn (ShiftLocation $l) => ['location' => $l, 'distance' => self::haversine($lat, $lng, (float) $l->latitude, (float) $l->longitude)])
            ->sortBy('distance')
            ->values();

        $inside = $measured->filter(fn (array $m) => $m['distance'] <= $m['location']->radius_m)->values();
        if ($inside->isNotEmpty()) {
            foreach ($inside as $m) {
                $threshold = $m['location']->accuracy_threshold_m ?? $settings->default_accuracy_threshold_m;
                if ($accuracy <= $threshold) {
                    return GeofenceOutcome::accept(GeofenceResult::Inside, $m['location'], $m['distance']);
                }
            }

            return GeofenceOutcome::reject(AttemptResult::LowAccuracy, $inside[0]['location'], $inside[0]['distance']);
        }

        $nearest = $measured[0];
        if ($settings->outside_geofence_action === OutsideGeofenceAction::AcceptAndReview) {
            return GeofenceOutcome::accept(GeofenceResult::OutsideAccepted, $nearest['location'], $nearest['distance']);
        }

        return GeofenceOutcome::reject(AttemptResult::Outside, $nearest['location'], $nearest['distance']);
    }

    /** Great-circle distance in metres (R = 6 371 008.8 m). */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * Locations of the most specific level with any active link: assignment (or its
     * roster assignment) → employee → the employee's branch on that date.
     *
     * @return Collection<int, ShiftLocation>
     */
    public function candidates(Employee $employee, ?ShiftOccurrence $occurrence, CarbonImmutable $at): Collection
    {
        $levels = [];
        if ($occurrence?->assignment !== null) {
            $levels[] = [[LocationLinkType::ShiftAssignment, $occurrence->assignment->id]];
            if ($occurrence->assignment->shift_schedule_assignment_id !== null) {
                $levels[] = [[LocationLinkType::RosterAssignment, (int) $occurrence->assignment->shift_schedule_assignment_id]];
            }
        }
        $levels[] = [[LocationLinkType::Employee, $employee->id]];
        $branchId = EmployeeSnapshot::asAt($employee, $at)->branchId;
        if ($branchId !== null) {
            $levels[] = [[LocationLinkType::Branch, $branchId]];
        }

        foreach ($levels as $level) {
            [$type, $id] = $level[0];
            $locations = ShiftLocation::query()
                ->where('is_active', true)
                ->whereHas('links', fn ($q) => $q->where('linkable_type', $type->value)->where('linkable_id', $id))
                ->get();
            if ($locations->isNotEmpty()) {
                return $locations->toBase();
            }
        }

        return collect();
    }

    private function onDutyToday(Employee $employee, CarbonImmutable $at): bool
    {
        return AttendanceRequest::query()
            ->where('employee_id', $employee->id)
            ->where('kind', AttendanceRequestKind::OnDuty->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->whereDate('from_date', '<=', $at->toDateString())
            ->whereDate('to_date', '>=', $at->toDateString())
            ->exists();
    }
}
