<?php

// M3 UI: every Kehadiran page renders (Inertia component + key props) for the right roles, the
// report screens and their XLSX/CSV exports, the Kakitangan "Kehadiran" tab data, the Kelulusan Saya
// attendance section, the review badge and the demo data seeder. Times are Asia/Kuala_Lumpur;
// 2026-11-02 is a Monday.

use App\Domain\Attendance\Actions\OvertimeClaimActions;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\LocationLinkType;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\EmployeeRestDay;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Models\ShiftLocationLink;
use App\Domain\Attendance\Services\AttendanceWriter;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoAttendanceSeeder;
use Database\Seeders\DemoLeaveSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\LeaveDefaultsSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function uiRow(Employee $e, string $date, array $attrs = []): Attendance
{
    return app(AttendanceWriter::class)->create($attrs + [
        'organisation_id' => $e->organisation_id, 'employee_id' => $e->id, 'attendance_date' => $date,
        'attendance_status' => 'present', 'day_type' => 'normal', 'source' => AttendanceSource::Manual, 'remarks' => 'ujian',
    ]);
}

function uiUser(Employee $e): User
{
    return User::query()->findOrFail($e->user_id);
}

function uiLocation(Employee $e): ShiftLocation
{
    $l = ShiftLocation::query()->create(['name' => 'Pejabat Kuantan', 'latitude' => 3.8077, 'longitude' => 103.3260, 'radius_m' => 150, 'accuracy_threshold_m' => 50]);
    ShiftLocationLink::query()->create(['shift_location_id' => $l->id, 'linkable_type' => LocationLinkType::Employee, 'linkable_id' => $e->id]);

    return $l;
}

/** Read an exported (not yet sent) download from disk. */
function uiExport($response): string
{
    $file = $response->baseResponse->getFile();

    return (string) file_get_contents($file->getPathname());
}

beforeEach(function () {
    $this->w = attendanceWorld('2026-11-02 07:30:00');
    $this->e = worker($this->w->pagi);
    weeklyRoster($this->e, $this->w->pagi);
    $this->hrm = fn () => actingInOrg($this->w->hr, $this->w->org);
    $this->hre = userIn($this->w->org, 'eksekutif_hr');
    $this->self = fn () => actingInOrg(uiUser($this->e), $this->w->org);
});

// ---- Check-in ------------------------------------------------------------------------------

it('renders the check-in page with server time, today\'s shift and the consent state', function () {
    ($this->self)()->get('/check-in')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Checkin')
        ->where('employee.id', $this->e->id)
        ->has('server_now')
        ->where('shift.code', 'PAGI')
        ->where('shift.time', '08:00–17:00')
        ->where('last_punch', null)
        ->where('next_action', 'checkin')
        ->where('geofence.required', true)
        ->where('consent.accepted', false)
        ->where('consent.retention_months', fn ($m) => $m > 0)
        ->where('checkin_required', true));
});

it('follows the consent, check-in and check-out flow the page drives', function () {
    uiLocation($this->e);
    ($this->self)();

    $this->postJson('/check-in', ['latitude' => 3.8077, 'longitude' => 103.326, 'accuracy' => 10])->assertStatus(409)->assertJsonPath('reason', 'consent_required');
    $this->post('/check-in/consent', ['accepted' => true])->assertRedirect();
    $this->get('/check-in')->assertInertia(fn (Assert $p) => $p->where('consent.accepted', true));

    $this->postJson('/check-in', ['latitude' => 3.8077, 'longitude' => 103.326, 'accuracy' => 10, 'client_request_id' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('location', 'Pejabat Kuantan')->assertJsonPath('log_type', 'in')->assertJsonStructure(['server_time', 'distance_m', 'geofence_result']);
    $this->travel(5)->hours();
    $this->get('/check-in')->assertInertia(fn (Assert $p) => $p
        ->where('last_punch.location', 'Pejabat Kuantan')->where('next_action', 'checkout'));

    $this->postJson('/check-in', ['latitude' => 3.9, 'longitude' => 103.5, 'accuracy' => 10])
        ->assertStatus(422)->assertJsonPath('reason', 'outside')->assertJsonPath('details.nearest_location', 'Pejabat Kuantan');
    $this->postJson('/check-in', ['latitude' => 3.8077, 'longitude' => 103.326, 'accuracy' => 900])
        ->assertStatus(422)->assertJsonPath('reason', 'low_accuracy');
});

it('keeps the check-in page for Kakitangan with a record only', function () {
    ($this->hrm)()->get('/check-in')->assertForbidden();
});

// ---- Kehadiran Saya ------------------------------------------------------------------------

it('renders Kehadiran Saya with the month grid, totals and request shortcuts', function () {
    uiRow($this->e, '2026-11-03', ['is_late' => true, 'late_minutes' => 12]);
    ($this->self)()->get('/me/attendance?month=2026-11-01')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Me')
        ->where('month', '2026-11')
        ->has('days', 30)
        ->where('days.1.shift.code', 'PAGI')
        ->where('days.6.day_type', 'rest_day')
        ->has('attendances', 1)
        ->where('attendances.0.is_late', true)
        ->where('summary.presentDays', '1.000')
        ->where('summary.lateCount', 1)
        ->where('can.request', true)->where('can.shift_request', true)
        ->has('checkins'));
});

// ---- Permohonan Kehadiran / Shift ----------------------------------------------------------

it('previews, files and shows a Permohonan Kehadiran with its route and per-day plan', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'attendance_request');
    ($this->self)();

    $this->postJson('/attendance-requests/preview', ['kind' => 'wfh', 'from_date' => '2026-11-03', 'to_date' => '2026-11-08', 'explanation' => 'Kerja dari rumah'])
        ->assertOk()->assertJsonCount(6, 'days')->assertJsonPath('days.0.outcome', 'created')->assertJsonPath('days.5.outcome', 'skipped_rest_or_holiday')
        ->assertJsonPath('route.steps.0.name', 'Pelulus');

    $this->post('/attendance-requests', ['kind' => 'wfh', 'from_date' => '2026-11-03', 'to_date' => '2026-11-04', 'explanation' => 'Kerja dari rumah', 'submit' => true])->assertRedirect();
    $id = DB::table('attendance_requests')->value('id');

    $this->get('/attendance-requests')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Requests/Index')->has('requests.data', 1)->where('requests.data.0.status', 'pending')
        ->has('options.kinds', 2)->has('options.sessions', 2)->where('can.create', true)->where('can.create_for_others', false)
        ->where('own_employee_id', $this->e->id));
    $this->get("/attendance-requests/{$id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Requests/Show')->where('request.kind', 'wfh')->has('preview', 2)->has('timeline.steps', 1)
        ->where('can.withdraw', true)->where('can.update', false));

    ($this->hrm)()->get('/attendance-requests')->assertInertia(fn (Assert $p) => $p
        ->has('requests.data', 1)->where('can.create_for_others', true)->has('options.employees'));
});

it('previews rest-day exclusion and requires an explanation', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'attendance_request');
    ($this->self)()->postJson('/attendance-requests/preview', ['kind' => 'wfh', 'from_date' => '2026-11-03'])->assertStatus(422);
});

it('files a Permohonan Shift and shows the decision context to the approver', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'shift_request');
    ($this->self)();
    $this->post('/shift-requests', ['shift_type_id' => $this->w->malam->id, 'from_date' => '2026-11-09', 'to_date' => '2026-11-10', 'reason' => 'Urusan keluarga', 'submit' => true])->assertRedirect();
    $id = DB::table('shift_requests')->value('id');

    $this->get('/shift-requests')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/ShiftRequests/Index')->has('requests.data', 1)->has('options.shift_types', 4)->where('options.max_days', 31)->where('can.create', true));
    $this->get("/shift-requests/{$id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/ShiftRequests/Show')->where('context.shift.code', 'MALAM')->has('context.days', 2)->where('context.days.0.current_shift', 'PAGI')
        ->has('context.conflicts.locked_dates')->has('timeline.steps', 1));
});

it('shows the three attendance sections in the Kelulusan Saya drawer', function () {
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'attendance_request');
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'shift_request');
    leaveWorkflow([leaveStep('Pelulus', $this->w->approver)], 'overtime_claim');
    ($this->self)();
    $this->post('/attendance-requests', ['kind' => 'on_duty', 'duty_location' => 'Klien', 'from_date' => '2026-11-03', 'explanation' => 'Mesyuarat', 'submit' => true]);
    $this->post('/shift-requests', ['shift_type_id' => $this->w->malam->id, 'from_date' => '2026-11-09', 'reason' => 'Sebab', 'submit' => true]);
    $ids = DB::table('approval_requests')->orderBy('id')->pluck('id', 'approval_type');

    actingInOrg($this->w->approver, $this->w->org);
    $this->get('/approvals?request='.$ids['attendance_request'])->assertInertia(fn (Assert $p) => $p
        ->where('selected.type', 'attendance_request')->where('selected.decision_context.kind', 'on_duty')
        ->where('selected.decision_context.duty_location', 'Klien')->has('selected.decision_context.preview', 1));
    $this->get('/approvals?request='.$ids['shift_request'])->assertInertia(fn (Assert $p) => $p
        ->where('selected.type', 'shift_request')->where('selected.decision_context.shift.code', 'MALAM')->has('selected.decision_context.change_set'));
});

// ---- Tuntutan OT ---------------------------------------------------------------------------

it('lists, generates and shows Tuntutan OT with the cap gauge data and line list', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00'));
    leaveWorkflow([leaveStep('Ketua', $this->w->approver)], 'overtime_claim');
    uiRow($this->e, '2026-11-03', ['worked_minutes' => 600, 'normal_minutes' => 480, 'ot_detected_minutes' => 120, 'overtime_type_id' => OvertimeType::query()->where('code', 'OT-AKTA')->value('id'), 'source' => AttendanceSource::Auto]);

    ($this->hrm)()->post('/attendance/overtime-claims/generate', ['month' => '2026-11'])->assertRedirect();
    $claim = DB::table('overtime_claims')->first();
    expect($claim)->not->toBeNull();

    $this->get('/attendance/overtime-claims?month=2026-11')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/OvertimeClaims/Index')->has('claims.data', 1)->where('claims.data.0.status', 'draft')
        ->where('claims.data.0.total_hours', '2.00')->where('can.manage', true)->where('can.submit', true));
    $this->get("/attendance/overtime-claims/{$claim->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/OvertimeClaims/Show')->has('claim.lines', 1)->where('claim.lines.0.beyond_normal_minutes', 120)
        ->where('claim.cap_exceeded', false)->has('options.day_types', 3)->has('route.steps', 1)->where('can.manage', true)->where('can.submit', true));

    // the employee sees only their own claim and cannot manage it
    ($this->self)()->get('/attendance/overtime-claims')->assertInertia(fn (Assert $p) => $p
        ->has('claims.data', 1)->where('can.manage', false)->where('can.submit', false));
});

it('adds a manual OT line and a manual wage through the page endpoints', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00'));
    uiRow($this->e, '2026-11-03', ['worked_minutes' => 600, 'normal_minutes' => 480, 'ot_detected_minutes' => 120, 'overtime_type_id' => OvertimeType::query()->where('code', 'OT-AKTA')->value('id'), 'source' => AttendanceSource::Auto]);
    ($this->hrm)();
    $claim = app(OvertimeClaimActions::class)->generate(CarbonImmutable::parse('2026-11-01'), $this->e->id)[0];

    $this->put("/attendance/overtime-claims/{$claim->id}", ['monthly_wage_snapshot' => '2600.00'])->assertRedirect();
    $this->put("/attendance/overtime-claims/{$claim->id}", ['line' => ['work_date' => '2026-11-04', 'day_type' => 'normal', 'worked_minutes' => 60, 'amount' => '12.50', 'note' => 'Kerja tambahan']])->assertRedirect();

    $this->get("/attendance/overtime-claims/{$claim->id}")->assertInertia(fn (Assert $p) => $p
        ->where('claim.monthly_wage_snapshot', fn ($v) => (float) $v === 2600.0)->has('claim.lines', 2)->where('claim.lines.1.is_manual', true));
});

// ---- Configuration pages -------------------------------------------------------------------

it('renders the configuration pages for HR Manager (edit) and HR Executive (read only)', function () {
    $pages = [
        '/settings/attendance' => 'Attendance/Settings',
        '/attendance/shift-types' => 'Attendance/ShiftTypes/Index',
        '/attendance/locations' => 'Attendance/Locations/Index',
        '/attendance/shift-schedules' => 'Attendance/Schedules/Index',
        '/attendance/overtime-types' => 'Attendance/OvertimeTypes/Index',
    ];

    foreach ($pages as $url => $component) {
        actingInOrg($this->w->hr, $this->w->org)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component)->where('can.manage', true));
        actingInOrg($this->hre, $this->w->org)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component)->where('can.manage', false));
        ($this->self)()->get($url)->assertForbidden();
    }
});

it('serves the shift-type, settings and schedule props the forms need', function () {
    ($this->hrm)();
    $this->get('/attendance/shift-types')->assertInertia(fn (Assert $p) => $p
        ->where('shift_types', fn ($rows) => collect($rows)->contains(fn ($r) => $r['code'] === 'PAGI' && $r['time_label'] === '08:00–17:00' && $r['normal_minutes'] === 480))
        ->has('options.single_punch_policies', 4)->has('options.holiday_lists')->has('options.overtime_types'));
    $this->get('/settings/attendance')->assertInertia(fn (Assert $p) => $p
        ->has('settings.geofence_enabled')->has('settings.location_notice_version')
        ->where('akta_kerja.verified', false)->has('akta_kerja.monthly_ot_cap_hours')
        ->has('options.no_location_policies', 2)->has('options.outside_geofence_actions', 2));
    $this->get('/attendance/shift-schedules')->assertInertia(fn (Assert $p) => $p
        ->where('schedules', fn ($rows) => collect($rows)->contains(fn ($r) => $r['code'] === '5H-SS' && count($r['days']) === 7))
        ->has('options.kinds', 3)->has('options.shift_types'));
    $this->get('/attendance/overtime-types')->assertInertia(fn (Assert $p) => $p
        ->where('overtime_types', fn ($rows) => collect($rows)->contains(fn ($r) => $r['code'] === 'OT-AKTA' && count($r['rules']) === 3))
        ->has('options.bases', 2)->has('options.within_normal_modes', 3));
});

it('checks a shift type live and keeps the Akta Kerja warnings', function () {
    ($this->hrm)()->postJson('/attendance/shift-types/check', [
        'code' => 'PANJANG', 'name' => 'Shift panjang', 'start_time' => '07:00', 'end_time' => '19:00', 'break_minutes' => 30,
        'open_before_minutes' => 60, 'close_after_minutes' => 60, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10,
        'half_day_threshold_minutes' => 240, 'absent_threshold_minutes' => 120, 'single_punch_policy' => 'review', 'absent_buffer_days' => 1, 'auto_attendance' => true, 'process_from' => '2026-11-01',
    ])->assertOk()->assertJsonPath('normal_minutes', 690)->assertJsonCount(0, 'errors')->assertJson(fn ($json) => $json->has('warnings', fn ($w) => $w->etc())->etc());
});

it('saves a location with coordinates, radius and links, and a pattern grid', function () {
    ($this->hrm)();
    $this->post('/attendance/locations', [
        'name' => 'Pejabat Kuantan', 'latitude' => '3.8077000', 'longitude' => '103.3260000', 'radius_m' => 120, 'accuracy_threshold_m' => null,
        'is_active' => true, 'links' => [['type' => 'employee', 'id' => $this->e->id]],
    ])->assertRedirect();
    $this->get('/attendance/locations')->assertInertia(fn (Assert $p) => $p
        ->where('locations.0.radius_m', 120)->where('locations.0.links.0.type', 'employee')->has('options.branches')->has('options.link_types', 4));

    $this->post('/attendance/shift-schedules', [
        'code' => 'UJI', 'name' => 'Ujian', 'cycle_length_days' => 3, 'is_active' => true,
        'days' => [['day_index' => 0, 'kind' => 'shift', 'shift_type_id' => $this->w->pagi->id], ['day_index' => 1, 'kind' => 'rest', 'shift_type_id' => null], ['day_index' => 2, 'kind' => 'off', 'shift_type_id' => null]],
    ])->assertRedirect();
    $this->get('/attendance/shift-schedules')->assertInertia(fn (Assert $p) => $p
        ->where('schedules', fn ($rows) => collect($rows)->contains(fn ($r) => $r['code'] === 'UJI' && $r['cycle_length_days'] === 3 && count($r['days']) === 3)));
});

// ---- Planning pages ------------------------------------------------------------------------

it('lists shift assignments and runs the bulk wizard preview then commit', function () {
    $other = worker();
    ($this->hrm)();

    $this->get('/attendance/shift-assignments')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/ShiftAssignments/Index')->where('can.manage', true)->has('assignments.data')
        ->has('options.shift_types', 4)->has('options.schedules')->has('options.employees')->has('options.locations')->has('options.sources'));

    $payload = ['employee_ids' => [$other->id], 'shift_type_id' => $this->w->malam->id, 'start_date' => '2026-12-07', 'end_date' => '2026-12-11', 'mode' => 'add'];
    $this->postJson('/attendance/shift-assignments', $payload + ['preview' => true])
        ->assertOk()->assertJsonPath('committed', false)->assertJsonPath('results.0.result', 'created');
    expect(ShiftAssignment::query()->where('employee_id', $other->id)->count())->toBe(0);

    $this->postJson('/attendance/shift-assignments', $payload)->assertOk()->assertJsonPath('committed', true);
    expect(ShiftAssignment::query()->where('employee_id', $other->id)->count())->toBe(1);

    // a second commit reports the conflict instead of failing
    $this->postJson('/attendance/shift-assignments', $payload + ['preview' => true])->assertOk()->assertJsonPath('results.0.result', 'conflict');
    $this->get('/attendance/shift-assignments?employee_id='.$other->id)->assertInertia(fn (Assert $p) => $p->has('assignments.data', 1)->where('assignments.data.0.source', 'bulk'));
});

it('renders the roster board and applies cell edits and a pattern', function () {
    $other = worker($this->w->pagi);
    ($this->hrm)();

    $this->get('/attendance/roster?from=2026-11-02&to=2026-11-08')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Roster')->where('from', '2026-11-02')->has('rows', 2)->has('rows.0.cells', 7)
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['employee']['id'] === $this->e->id && $r['cells'][0]['shift']['code'] === 'PAGI' && $r['cells'][6]['day_type'] === 'rest_day'))
        ->has('roster_assignments', 1)->has('options.schedules')->where('can.manage', true));

    $this->post('/attendance/roster', ['action' => 'set_rest', 'employee_ids' => [$this->e->id], 'from_date' => '2026-11-04', 'to_date' => '2026-11-04'])->assertRedirect();
    expect(EmployeeRestDay::query()->where('employee_id', $this->e->id)->whereDate('rest_date', '2026-11-04')->exists())->toBeTrue();

    $this->post('/attendance/roster', ['action' => 'set_shift', 'employee_ids' => [$this->e->id], 'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'shift_type_id' => $this->w->malam->id])->assertRedirect();
    $this->get('/attendance/roster?from=2026-11-02&to=2026-11-08')->assertInertia(fn (Assert $p) => $p
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['employee']['id'] === $this->e->id && $r['cells'][3]['shift']['code'] === 'MALAM' && $r['cells'][2]['day_type'] === 'rest_day')));

    $this->post('/attendance/roster', ['action' => 'swap', 'employee_ids' => [$this->e->id], 'other_employee_id' => $other->id, 'from_date' => '2026-11-05', 'to_date' => '2026-11-05'])->assertRedirect();
    $this->get('/attendance/roster?from=2026-11-02&to=2026-11-08')->assertInertia(fn (Assert $p) => $p
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['employee']['id'] === $other->id && $r['cells'][3]['shift']['code'] === 'MALAM')));
});

it('renders Tanda Kehadiran Pukal with row states and marks through the JSON endpoint', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00:00'));
    ($this->hrm)();

    $this->get('/attendance/bulk-mark?date=2026-11-03')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/BulkMark')->where('date', '2026-11-03')->where('rows.0.state', 'unmarked')->where('rows.0.read_only', false)
        ->has('options.statuses', 5)->has('options.shift_types')->where('max_days', 31));

    $this->postJson('/attendance/bulk-mark', ['employee_ids' => [$this->e->id], 'from_date' => '2026-11-03', 'attendance_status' => 'present', 'remarks' => 'Lupa check-in'])
        ->assertOk()->assertJsonPath('created', 1)->assertJsonPath('superseded', 0)->assertJsonCount(0, 'refused');

    $this->get('/attendance/bulk-mark?date=2026-11-03')->assertInertia(fn (Assert $p) => $p->where('rows.0.state', 'present')->where('rows.0.attendance.source', 'bulk'));
    $this->postJson('/attendance/bulk-mark', ['employee_ids' => [$this->e->id], 'from_date' => '2026-11-03', 'attendance_status' => 'absent'])->assertStatus(422);
});

it('lists review items with the sidebar badge and acts on them', function () {
    $punch = punchAt($this->e, '2026-11-02 08:00:00');
    processAt('2026-11-03 09:00:00');
    expect($punch->refresh()->processing_state->value)->toBe('needs_review');
    ($this->hrm)();

    $this->get('/attendance/review')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Review')->where('attendanceReviewCount', 1)
        ->where('groups.0.reason', 'single_punch')->has('groups.0.checkins', 1)->where('counts.single_punch', 1)
        ->has('flagged_records')->has('options.reasons', 9));
    $this->get('/attendance/review?reason=off_shift')->assertInertia(fn (Assert $p) => $p->has('groups', 0));

    actingInOrg(uiUser($this->e), $this->w->org)->get('/me/attendance')->assertInertia(fn (Assert $p) => $p->where('attendanceReviewCount', 0));
    actingInOrg($this->hre, $this->w->org)->get('/attendance/review')->assertOk()->assertInertia(fn (Assert $p) => $p->where('attendanceReviewCount', 1));

    ($this->hrm)()->post('/attendance/review', ['action' => 'ignore', 'checkin_ids' => [$punch->id], 'note' => 'Lupa keluar'])->assertRedirect();
    expect($punch->refresh()->processing_state->value)->toBe('ignored');
    $this->get('/attendance/review')->assertInertia(fn (Assert $p) => $p->has('groups', 0)->where('attendanceReviewCount', 0));
});

it('renders the record list, the record detail with history and the check-in log', function () {
    $row = uiRow($this->e, '2026-11-03', ['in_at' => '2026-11-03 08:00:00', 'out_at' => '2026-11-03 17:00:00', 'worked_minutes' => 480]);
    $punch = punchAt($this->e, '2026-11-03 08:00:00');
    EmployeeCheckin::query()->whereKey($punch->id)->update(['latitude' => 3.8077, 'longitude' => 103.326, 'distance_m' => 20]);
    ($this->hrm)();

    $this->get('/attendance/records?employee_id='.$this->e->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Records/Index')->has('records.data', 1)->where('records.data.0.attendance_status', 'present')
        ->has('options.attendance_statuses', 6)->where('can.manage', true));
    $this->get("/attendance/records/{$row->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Records/Show')->where('record.id', $row->id)->has('history', 0)->where('superseded_by', null)->where('can.manage', true));
    $this->get('/attendance/checkins')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Checkins')->has('checkins.data', 1)->where('checkins.data.0.latitude', '3.8077000')->where('can.void', true)->where('can.view_coordinates', true));

    actingInOrg($this->hre, $this->w->org)->get('/attendance/checkins')->assertInertia(fn (Assert $p) => $p
        ->where('checkins.data.0.latitude', null)->where('can.void', false)->where('can.view_coordinates', false));

    foreach (['/attendance/records', '/attendance/checkins', '/attendance/review', '/attendance/bulk-mark', '/attendance/roster', '/attendance/shift-assignments'] as $url) {
        ($this->self)()->get($url)->assertForbidden();
    }
});

// ---- Employee tab ---------------------------------------------------------------------------

it('exposes the Kehadiran tab flag and the profile JSON for HR only', function () {
    ($this->hrm)()->get('/employees/'.$this->e->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('can.attendance', true)->where('can.attendanceManage', true));
    ($this->self)()->get('/employees/'.$this->e->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('can.attendance', false)->where('can.attendanceManage', false));

    ($this->hrm)()->getJson('/attendance/employees/'.$this->e->id.'/profile')->assertOk()
        ->assertJsonPath('profile.checkin_required', true)->assertJsonPath('profile.default_shift_type_id', $this->w->pagi->id)
        ->assertJsonCount(3, 'options.ot_eligibility')->assertJsonCount(4, 'options.shift_types');
    ($this->self)()->getJson('/attendance/employees/'.$this->e->id.'/profile')->assertForbidden();

    ($this->hrm)()->put('/attendance/employees/'.$this->e->id.'/profile', ['checkin_required' => true, 'geofence_exempt' => true, 'is_manual_labour' => false, 'ot_eligibility' => 'auto', 'default_shift_type_id' => null])->assertRedirect();
    $this->getJson('/attendance/employees/'.$this->e->id.'/profile')->assertJsonPath('profile.geofence_exempt', true)->assertJsonPath('profile.default_shift_type_id', null);
});

// ---- Reports ---------------------------------------------------------------------------------

it('builds the monthly attendance matrix with codes, markers and totals', function () {
    uiRow($this->e, '2026-11-03', ['is_late' => true, 'late_minutes' => 12, 'worked_minutes' => 480]);
    uiRow($this->e, '2026-11-04', ['attendance_status' => 'absent']);
    uiRow($this->e, '2026-11-05', ['attendance_status' => 'half_day', 'half_day_basis' => 'leave', 'other_half_status' => 'present', 'source' => AttendanceSource::Leave]);
    uiRow($this->e, '2026-11-06', ['attendance_status' => 'wfh']);
    uiRow($this->e, '2026-11-07', ['attendance_status' => 'present', 'day_type' => 'rest_day']);
    ($this->hrm)();

    $this->get('/reports/attendance/monthly?month=2026-11')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Reports/Show')->where('report', 'monthly')->where('kind', 'matrix')->has('days', 30)->has('reports', 6)
        ->where('rows.0.employee', $this->e->full_name)
        ->where('rows.0.cells.2.c', 'H')->where('rows.0.cells.2.f', 'L')
        ->where('rows.0.cells.3.c', 'TH')->where('rows.0.cells.4.c', '½C·H')->where('rows.0.cells.5.c', 'BR')
        ->where('rows.0.cells.6.c', 'H')->where('rows.0.cells.6.t', 'rest_day')
        ->where('rows.0.cells.1.c', '')->where('rows.0.cells.7.c', 'R')
        ->where('rows.0.absent', '1.0')->where('rows.0.leave_days', '0.5')->where('rows.0.wfh', '1.0')->where('rows.0.half_days', 1)
        ->where('rows.0.late_count', 1)->where('rows.0.late_minutes', 12)->where('rows.0.worked_hours', '8.0'));

    $this->get('/reports/attendance/monthly?month=2026-12')->assertInertia(fn (Assert $p) => $p->where('rows.0.cells.24.c', 'CU')->where('rows.0.public_holidays', 1));
});

it('builds the shift, late, overtime, roster and check-in reports', function () {
    uiRow($this->e, '2026-11-03', ['shift_type_id' => $this->w->pagi->id, 'scheduled_start_at' => '2026-11-03 08:00:00', 'scheduled_end_at' => '2026-11-03 17:00:00', 'in_at' => '2026-11-03 08:05:00', 'out_at' => '2026-11-03 16:55:00', 'worked_minutes' => 530]);
    uiRow($this->e, '2026-11-04', ['is_late' => true, 'late_minutes' => 20, 'is_early_exit' => true, 'early_exit_minutes' => 30]);
    ($this->hrm)();

    $this->get('/reports/attendance/shift?from=2026-11-03&to=2026-11-04')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('kind', 'table')->has('rows', 2)->where('rows.0.shift', 'PAGI')->where('rows.0.scheduled_in', '08:00')->where('rows.0.actual_in', '08:05')
        ->where('rows.0.late_minutes', 0)->where('rows.0.early_minutes', 0)
        ->has('summary.present')->has('summary.remote')->has('summary.leave')->has('summary.absent')->has('summary.unmarked')->has('summary.review'));
    $this->get('/reports/attendance/shift?from=2026-11-03&to=2026-11-04&no_grace=1')->assertInertia(fn (Assert $p) => $p
        ->where('rows.0.late_minutes', 5)->where('rows.0.early_minutes', 5));

    $this->get('/reports/attendance/late?from=2026-11-01&to=2026-11-30')->assertInertia(fn (Assert $p) => $p
        ->has('rows', 1)->where('rows.0.late_count', 1)->where('rows.0.late_minutes', 20)->where('rows.0.early_count', 1)->where('rows.0.early_minutes', 30)
        ->has('rollup', 1)->where('rollup.0.employees', 1)->where('details', []));
    $this->get('/reports/attendance/late?from=2026-11-01&to=2026-11-30&min_count=3')->assertInertia(fn (Assert $p) => $p->has('rows', 0));
    $this->get('/reports/attendance/late?from=2026-11-01&to=2026-11-30&employee_id='.$this->e->id)->assertInertia(fn (Assert $p) => $p
        ->has('details', 1)->where('details.0.late_minutes', 20));

    $this->get('/reports/attendance/roster?from=2026-11-02&to=2026-11-08')->assertInertia(fn (Assert $p) => $p
        ->where('kind', 'matrix')->has('days', 7)->where('rows.0.cells.0.c', 'PAGI')->where('rows.0.cells.6.c', 'R'));

    $punch = punchAt($this->e, '2026-11-03 08:00:00');
    EmployeeCheckin::query()->whereKey($punch->id)->update(['latitude' => 3.8077, 'longitude' => 103.326, 'distance_m' => 20]);
    $this->get('/reports/attendance/checkins?from=2026-11-01&to=2026-11-30')->assertInertia(fn (Assert $p) => $p
        ->has('rows', 1)->where('rows.0.employee_no', $this->e->employee_no)
        ->where('columns', fn ($cols) => ! collect($cols)->pluck('key')->intersect(['latitude', 'longitude'])->count()));
});

it('builds the OT report with cap usage and flags', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00'));
    $type = OvertimeType::query()->where('code', 'OT-AKTA')->value('id');
    uiRow($this->e, '2026-11-03', ['worked_minutes' => 780, 'normal_minutes' => 480, 'ot_detected_minutes' => 300, 'overtime_type_id' => $type, 'source' => AttendanceSource::Auto]);
    uiRow($this->e, '2026-11-08', ['worked_minutes' => 240, 'normal_minutes' => 480, 'ot_detected_minutes' => 240, 'day_type' => 'rest_day', 'overtime_type_id' => $type, 'source' => AttendanceSource::Auto]);
    $claim = app(OvertimeClaimActions::class)->generate(CarbonImmutable::parse('2026-11-01'), $this->e->id)[0];
    app(OvertimeClaimActions::class)->setManualWage($claim, '2600.00');
    ($this->hrm)();

    $this->get('/reports/attendance/overtime?month=2026-11')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('rows', 1)->where('rows.0.employee', $this->e->full_name)->where('rows.0.normal_minutes', 300)->where('rows.0.rest_minutes', 240)->where('rows.0.holiday_minutes', 0)
        ->where('rows.0.total_hours', '9.00')->where('rows.0.cap_exceeded', 'Tidak')->where('rows.0.flagged_lines', 1)->where('rows.0.flags', 'lebih 12 jam')
        ->where('rows.0.status', 'Draf'));
});

it('exports every report as XLSX and CSV with translated headers and without coordinates', function () {
    uiRow($this->e, '2026-11-03', ['is_late' => true, 'late_minutes' => 12, 'shift_type_id' => $this->w->pagi->id, 'scheduled_start_at' => '2026-11-03 08:00:00', 'in_at' => '2026-11-03 08:12:00']);
    $punch = punchAt($this->e, '2026-11-03 08:00:00');
    EmployeeCheckin::query()->whereKey($punch->id)->update(['latitude' => 3.8077123, 'longitude' => 103.3260456, 'distance_m' => 20]);
    ($this->hrm)();

    $query = ['monthly' => 'month=2026-11', 'shift' => 'from=2026-11-01&to=2026-11-30', 'late' => 'from=2026-11-01&to=2026-11-30', 'overtime' => 'month=2026-11', 'roster' => 'from=2026-11-02&to=2026-11-08', 'checkins' => 'from=2026-11-01&to=2026-11-30'];
    foreach ($query as $report => $q) {
        $xlsx = $this->get("/reports/attendance/{$report}/export?{$q}&format=xlsx")->assertOk();
        expect($xlsx->headers->get('content-disposition'))->toContain("laporan-kehadiran-{$report}-")->toContain('.xlsx');
        expect(uiExport($xlsx))->toStartWith('PK');

        $csv = $this->get("/reports/attendance/{$report}/export?{$q}&format=csv")->assertOk();
        $content = uiExport($csv);
        expect($csv->headers->get('content-disposition'))->toContain('.csv')
            ->and($content)->toContain('Kakitangan')->not->toContain('attendance.reports.columns')
            ->and($content)->not->toContain('3.8077')->not->toContain('103.326');
    }

    $matrix = uiExport($this->get('/reports/attendance/monthly/export?month=2026-11&format=csv'));
    expect($matrix)->toContain('H L')->toContain($this->e->full_name);
});

it('never leaves a raw translation key as a report column or description', function () {
    ($this->hrm)();
    foreach (['monthly', 'shift', 'late', 'overtime', 'roster', 'checkins'] as $report) {
        $this->get("/reports/attendance/{$report}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('columns', fn ($cols) => collect($cols)->every(fn ($c) => ! str_starts_with($c['label'], 'attendance.'))));
        expect(__("attendance.reports.names.{$report}"))->not->toStartWith('attendance.')
            ->and(__("attendance.reports.descriptions.{$report}"))->not->toStartWith('attendance.');
    }
});

it('guards the reports by role and hides the check-in log from payroll approvers', function () {
    $payroll = userIn($this->w->org, 'pegawai_payroll');
    $approver = userIn($this->w->org, 'pelulus_payroll');

    ($this->self)()->get('/reports/attendance/monthly')->assertForbidden();
    ($this->self)()->get('/reports/attendance/monthly/export')->assertForbidden();

    actingInOrg($payroll, $this->w->org)->get('/reports/attendance/monthly')->assertOk()->assertInertia(fn (Assert $p) => $p->has('reports', 6));
    actingInOrg($approver, $this->w->org)->get('/reports/attendance/monthly')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('reports', fn ($r) => ! in_array('checkins', collect($r)->all(), true)));
    actingInOrg($approver, $this->w->org)->get('/reports/attendance/checkins')->assertForbidden();
    actingInOrg($approver, $this->w->org)->get('/reports/attendance/checkins/export')->assertForbidden();

    ($this->hrm)()->get('/reports/attendance/unknown')->assertNotFound();
});

// ---- Demo data ---------------------------------------------------------------------------------

it('seeds demo Kehadiran data in every state and is idempotent and skipped in production', function () {
    $this->travelBack();
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->seed(LeaveDefaultsSeeder::class);
    $this->seed(DemoLeaveSeeder::class);

    $original = app()->environment();
    app()->detectEnvironment(fn () => 'production');
    (new DemoAttendanceSeeder)->run();
    app()->detectEnvironment(fn () => $original);
    $pahang = Organisation::query()->where('short_code', 'SDT')->firstOrFail();
    OrganisationContext::run($pahang, fn () => expect(ShiftLocation::query()->count())->toBe(0));

    $this->seed(DemoAttendanceSeeder::class);
    $this->seed(DemoAttendanceSeeder::class); // idempotent

    OrganisationContext::run($pahang, function () {
        expect(ShiftLocation::query()->count())->toBe(2)
            ->and(ShiftAssignment::query()->distinct('source')->pluck('source')->map(fn ($s) => $s->value)->sort()->values()->all())->toContain('roster', 'manual', 'bulk')
            ->and(EmployeeCheckin::query()->count())->toBeGreaterThan(100);

        $statuses = Attendance::query()->live()->pluck('attendance_status')->map(fn ($s) => $s->value)->unique()->sort()->values()->all();
        expect($statuses)->toContain('present', 'absent')
            ->and(Attendance::query()->live()->where('is_late', true)->exists())->toBeTrue()
            ->and(Attendance::query()->live()->where('is_early_exit', true)->exists())->toBeTrue()
            ->and(Attendance::query()->live()->where('ot_detected_minutes', '>', 0)->exists())->toBeTrue()
            ->and(EmployeeCheckin::query()->where('processing_state', 'needs_review')->exists())->toBeTrue();

        $requestStates = DB::table('attendance_requests')->pluck('status')->unique()->sort()->values()->all();
        expect($requestStates)->toContain('approved', 'pending', 'rejected', 'draft')
            ->and(DB::table('shift_requests')->count())->toBeGreaterThan(1)
            ->and(DB::table('overtime_claims')->count())->toBeGreaterThan(0)
            ->and(DB::table('overtime_claims')->where('status', 'pending')->count())->toBeLessThanOrEqual(1);
    });
});
