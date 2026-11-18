<?php

// spec-03 §11 cases 32–41 (geofence, check-in action, PDPA consent, retention purge) and edges 10–14.

use App\Domain\Attendance\Actions\RecordLocationConsent;
use App\Domain\Attendance\Enums\LocationLinkType;
use App\Domain\Attendance\Jobs\PurgeLocationData;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\CheckinAttemptLog;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Models\ShiftLocationLink;
use App\Domain\Attendance\Services\GeofenceEvaluator;
use App\Domain\Attendance\Services\ShiftAssignmentWriter;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeWorkHistory;
use App\Models\User;
use Carbon\CarbonImmutable;

function location(string $name, float $lat, float $lng, int $radius = 100, ?int $threshold = 50): ShiftLocation
{
    return ShiftLocation::query()->create(['name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => $radius, 'accuracy_threshold_m' => $threshold]);
}

function linkLocation(ShiftLocation $l, LocationLinkType $type, int $id): void
{
    ShiftLocationLink::query()->create(['shift_location_id' => $l->id, 'linkable_type' => $type, 'linkable_id' => $id]);
}

function checkIn(Employee $e, array $payload = [])
{
    return test()->actingAs(User::query()->findOrFail($e->user_id))->postJson('/check-in', $payload);
}

beforeEach(function () {
    $this->w = attendanceWorld('2026-11-02 08:00:00');
    $this->e = worker();
    weeklyRoster($this->e, $this->w->pagi);
    $this->l1 = location('Pejabat Kuantan', 3.8077, 103.3260);
    linkLocation($this->l1, LocationLinkType::Employee, $this->e->id);
    app(RecordLocationConsent::class)->decide($this->e, true, 'Pest');
    User::query()->findOrFail($this->e->user_id)->forceFill(['current_organisation_id' => $this->w->org->id])->save();
});

it('accepts 88.96 m inside and rejects 100.08 m outside with an attempt log (case 32)', function () {
    checkIn($this->e, ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 20])
        ->assertCreated()->assertJsonPath('location', 'Pejabat Kuantan')->assertJsonPath('distance_m', 89);
    expect(round((float) EmployeeCheckin::query()->first()->distance_m, 2))->toBe(88.96);

    $this->travel(2)->minutes();
    checkIn($this->e, ['latitude' => 3.8086, 'longitude' => 103.3260, 'accuracy' => 20])
        ->assertStatus(422)->assertJsonPath('reason', 'outside');
    $log = CheckinAttemptLog::query()->latest('id')->first();
    expect($log->result->value)->toBe('outside')->and($log->distance_m)->toBe(100)
        ->and(EmployeeCheckin::query()->count())->toBe(1);
});

it('rejects a point inside the geofence with poor accuracy (case 33)', function () {
    checkIn($this->e, ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 80])
        ->assertStatus(422)->assertJsonPath('reason', 'low_accuracy');
});

it('evaluates every location and picks the nearest containing one (case 34)', function () {
    ShiftLocationLink::query()->delete();
    $far = location('Jauh', 3.8527, 103.3260); // ~5 km north
    linkLocation($far, LocationLinkType::Employee, $this->e->id);
    linkLocation($this->l1, LocationLinkType::Employee, $this->e->id);

    checkIn($this->e, ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 20])
        ->assertCreated()->assertJsonPath('location', 'Pejabat Kuantan');
});

it('applies the no-location policy (case 35)', function () {
    ShiftLocationLink::query()->delete();
    $evaluator = app(GeofenceEvaluator::class);

    expect($evaluator->evaluate($this->e, null, 3.0, 101.0, 10.0)->result?->value)->toBe('not_required');
    AttendanceSettings::current()->forceFill(['no_location_policy' => 'deny'])->save();
    $outcome = $evaluator->evaluate($this->e, null, 3.0, 101.0, 10.0);
    expect($outcome->accepted)->toBeFalse()->and($outcome->rejection?->value)->toBe('no_location');
});

it('bypasses the geofence on an approved on-duty day (case 36)', function () {
    $r = AttendanceRequest::query()->create(['employee_id' => $this->e->id, 'kind' => 'on_duty', 'from_date' => '2026-11-02', 'to_date' => '2026-11-02',
        'duty_location' => 'Kuala Lumpur', 'explanation' => 'Mesyuarat']);
    $r->forceFill(['status' => 'approved'])->saveQuietly();

    checkIn($this->e, ['latitude' => 3.8347, 'longitude' => 103.3260, 'accuracy' => 20]) // ~3 km away
        ->assertCreated()->assertJsonPath('geofence_result', 'on_duty_bypass');
});

it('uses the most specific link level: assignment > employee > branch (case 37)', function () {
    $branch = Branch::query()->first() ?? Branch::factory()->create();
    $this->e->forceFill(['branch_id' => $branch->id])->save();
    EmployeeWorkHistory::query()->where('employee_id', $this->e->id)->update(['branch_id' => $branch->id]);
    $branchLoc = location('Cawangan', 3.9, 103.4);
    linkLocation($branchLoc, LocationLinkType::Branch, $branch->id);
    $evaluator = app(GeofenceEvaluator::class);
    $now = CarbonImmutable::now();
    $occurrence = app(ShiftCalendar::class)->occurrenceAt($this->e, $now);

    expect($evaluator->candidates($this->e, $occurrence, $now)->pluck('name')->all())->toBe(['Pejabat Kuantan']);

    $assignmentLoc = location('Tapak Projek', 3.7, 103.2);
    linkLocation($assignmentLoc, LocationLinkType::ShiftAssignment, $occurrence->assignment->id);
    expect($evaluator->candidates($this->e, $occurrence, $now)->pluck('name')->all())->toBe(['Tapak Projek']);

    ShiftLocationLink::query()->whereIn('linkable_type', ['employee', 'shift_assignment'])->delete();
    expect($evaluator->candidates($this->e, $occurrence, $now)->pluck('name')->all())->toBe(['Cawangan']);
});

it('stores server time and the client clock skew (case 38, edge 10)', function () {
    $client = CarbonImmutable::now()->addMinutes(10);
    checkIn($this->e, ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 20, 'client_time' => $client->toIso8601String()])->assertCreated();

    $punch = EmployeeCheckin::query()->first();
    expect($punch->punched_at->toDateTimeString())->toBe('2026-11-02 08:00:00')
        ->and($punch->clock_skew_seconds)->toBe(600)
        ->and($punch->log_type?->value)->toBe('in');
});

it('produces one row for a double tap and for a replayed request id (case 39, edge 14)', function () {
    $payload = ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 20, 'client_request_id' => '6f1c2a7e-3a54-4f0e-9a5e-1f2b3c4d5e6f'];
    checkIn($this->e, $payload)->assertCreated();
    $this->travel(20)->seconds();
    checkIn($this->e, ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 20])->assertOk()->assertJsonPath('duplicate', true);
    $this->travel(5)->minutes();
    checkIn($this->e, $payload)->assertOk()->assertJsonPath('duplicate', true);

    expect(EmployeeCheckin::query()->count())->toBe(1);
});

it('requires consent for the current notice version (case 40, edge 12)', function () {
    $other = worker();
    linkLocation($this->l1, LocationLinkType::Employee, $other->id);
    User::query()->findOrFail($other->user_id)->forceFill(['current_organisation_id' => $this->w->org->id])->save();
    $payload = ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 20];

    checkIn($other, $payload)->assertStatus(409)->assertJsonPath('reason', 'consent_required');
    app(RecordLocationConsent::class)->decide($other, true, 'Pest');
    checkIn($other, $payload)->assertCreated();

    AttendanceSettings::current()->forceFill(['location_notice_version' => 2])->save();
    $this->travel(5)->minutes();
    checkIn($other, $payload)->assertStatus(409);
});

it('purges coordinates after the retention period but keeps distance and result (case 41)', function () {
    checkIn($this->e, ['latitude' => 3.8085, 'longitude' => 103.3260, 'accuracy' => 20])->assertCreated();
    $punch = EmployeeCheckin::query()->first();
    expect($punch->latitude)->not->toBeNull()->and($punch->ip_address)->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2027-12-03 03:00:00'));
    (new PurgeLocationData)->handle();

    $punch->refresh();
    expect($punch->latitude)->toBeNull()->and($punch->longitude)->toBeNull()
        ->and($punch->ip_address)->toBeNull()->and($punch->user_agent)->toBeNull()
        ->and($punch->distance_m)->not->toBeNull()
        ->and($punch->geofence_result->value)->toBe('inside');
});

it('keeps punch time and coordinates immutable (model guard)', function () {
    $punch = punchAt($this->e, '2026-11-02 08:01:00');
    expect(fn () => $punch->forceFill(['punched_at' => '2026-11-02 07:00:00'])->save())->toThrow(LogicException::class);
});

it('re-resolves a pending punch after a back-dated assignment (edge 5)', function () {
    $e = worker();
    $p = punchAt($e, '2026-11-02 08:00:00');
    expect($p->shift_type_id)->toBeNull();

    app(ShiftAssignmentWriter::class)->add($e, $this->w->pagi, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));
    expect($p->refresh()->shift_type_id)->toBe($this->w->pagi->id);
});
