<?php

// spec-03 §11 cases 59–61 (payroll contract), 63–65 (permissions) and HTTP wiring of the M3 routes.

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Attendance\Services\AttendanceWriter;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function rowFor(Employee $e, string $date, array $attrs): Attendance
{
    return app(AttendanceWriter::class)->create($attrs + [
        'organisation_id' => $e->organisation_id, 'employee_id' => $e->id, 'attendance_date' => $date,
        'day_type' => 'normal', 'source' => AttendanceSource::Manual, 'remarks' => 'ujian',
    ]);
}

it('sums absences with half fractions and lists them per date (cases 59, 61)', function () {
    attendanceWorld('2026-12-01 09:00:00', [6]);
    $e = worker();
    rowFor($e, '2026-11-03', ['attendance_status' => 'absent']);
    rowFor($e, '2026-11-04', ['attendance_status' => 'absent']);
    rowFor($e, '2026-11-05', ['attendance_status' => 'half_day', 'half_day_basis' => 'worked', 'other_half_status' => 'absent']);
    rowFor($e, '2026-11-07', ['attendance_status' => 'absent', 'is_half_day_off' => true]);
    rowFor($e, '2026-11-06', ['attendance_status' => 'on_leave', 'source' => AttendanceSource::Leave]);

    $summary = app(AttendanceForPayroll::class)->periodSummary($e, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect($summary->absentDays)->toBe('3.000')
        ->and(array_column($summary->absentDates, 'fraction', 'date'))->toBe(['2026-11-03' => '1.000', '2026-11-04' => '1.000', '2026-11-05' => '0.500', '2026-11-07' => '0.500'])
        ->and($summary->onLeaveDays)->toBe('1.000')
        ->and($summary->presentDays)->toBe('0.500');
});

it('reports unmarked normal days and completeness (case 60)', function () {
    $w = attendanceWorld('2026-11-05 09:00:00');
    $e = worker();
    $payroll = app(AttendanceForPayroll::class);

    $summary = $payroll->periodSummary($e, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'));
    expect($summary->unmarkedDays)->toBe(1)->and($summary->unmarkedDates)->toBe(['2026-11-02']);

    $list = $payroll->completeness($w->org->id, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02'), [$e->id]);
    expect($list)->toHaveCount(1)->and($list[0]->employeeId)->toBe($e->id);
});

it('gives a reports_to manager no access to a subordinate\'s attendance (case 63)', function () {
    $w = attendanceWorld('2026-11-05 09:00:00');
    $boss = worker();
    $e = worker(null, ['reports_to_id' => $boss->id]);
    $row = rowFor($e, '2026-11-03', ['attendance_status' => 'absent']);
    $bossUser = User::query()->findOrFail($boss->user_id);

    actingInOrg($bossUser, $w->org)->get('/attendance/records/'.$row->id)->assertForbidden();
    actingInOrg($bossUser, $w->org)->get('/attendance/checkins')->assertForbidden();
    actingInOrg($bossUser, $w->org)->get('/attendance/records')->assertForbidden();
});

it('shows coordinates only to HR Manager and to the employee for their own punches (case 64)', function () {
    $w = attendanceWorld('2026-11-02 08:00:00');
    $e = worker();
    $punch = punchAt($e, '2026-11-02 08:00:00');
    $punch->forceFill(['distance_m' => 12.5])->save();
    EmployeeCheckin::query()->whereKey($punch->id)->update(['latitude' => 3.8077, 'longitude' => 103.326]);
    $exec = userIn($w->org, 'eksekutif_hr');

    actingInOrg($exec, $w->org)->get('/attendance/checkins')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Checkins')->where('checkins.data.0.latitude', null)->where('checkins.data.0.distance_m', '12.50'));
    actingInOrg($w->hr, $w->org)->get('/attendance/checkins')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('checkins.data.0.latitude', '3.8077000'));

    $user = User::query()->findOrFail($e->user_id);
    actingInOrg($user, $w->org)->get('/attendance/checkins')->assertForbidden();
    actingInOrg($user, $w->org)->get('/me/attendance?month=2026-11-01')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Me')->where('checkins.0.latitude', '3.8077000'));
});

it('grants the spec-03 §8 matrix rows to the right roles (case 65)', function () {
    $w = attendanceWorld();
    $roles = app(RoleService::class);
    $expect = [
        'attendance.config.manage' => ['pengurus_hr'],
        'attendance.manage' => ['pengurus_hr', 'eksekutif_hr'],
        'attendance.review.manage' => ['pengurus_hr', 'eksekutif_hr'],
        'attendance.checkin' => ['kakitangan'],
        'attendance.checkin.void' => ['pengurus_hr'],
        'attendance.location.view-coordinates' => ['pengurus_hr'],
        'attendance.overtime.approve-any' => ['pengurus_hr'],
        'attendance.overtime.submit-for-approval' => ['pengurus_hr', 'eksekutif_hr'],
        'attendance.overtime.cancel' => ['pengurus_hr'],
    ];
    foreach ($expect as $ability => $holders) {
        foreach (PermissionMatrix::roles() as $role) {
            $user = userIn($w->org, $role);
            expect($roles->can($user, $w->org->id, $ability))->toBe(in_array($role, $holders, true), "$ability / $role");
        }
    }
});

it('renders every M3 page with its props', function (string $url, string $component) {
    $w = attendanceWorld('2026-11-02 09:00:00');
    $me = worker();
    $me->forceFill(['user_id' => $w->hr->id])->save();

    actingInOrg($w->hr, $w->org)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/check-in', 'Attendance/Checkin'],
    ['/me/attendance', 'Attendance/Me'],
    ['/attendance-requests', 'Attendance/Requests/Index'],
    ['/shift-requests', 'Attendance/ShiftRequests/Index'],
    ['/settings/attendance', 'Attendance/Settings'],
    ['/attendance/shift-types', 'Attendance/ShiftTypes/Index'],
    ['/attendance/locations', 'Attendance/Locations/Index'],
    ['/attendance/shift-schedules', 'Attendance/Schedules/Index'],
    ['/attendance/overtime-types', 'Attendance/OvertimeTypes/Index'],
    ['/attendance/shift-assignments', 'Attendance/ShiftAssignments/Index'],
    ['/attendance/roster', 'Attendance/Roster'],
    ['/attendance/records', 'Attendance/Records/Index'],
    ['/attendance/bulk-mark', 'Attendance/BulkMark'],
    ['/attendance/review', 'Attendance/Review'],
    ['/attendance/checkins', 'Attendance/Checkins'],
    ['/attendance/overtime-claims', 'Attendance/OvertimeClaims/Index'],
]);

it('runs HR write paths over HTTP: bulk mark, request + show, OT generate + show', function () {
    $w = attendanceWorld('2026-11-10 09:00:00');
    leaveWorkflow([leaveStep('Pelulus', $w->approver)], 'attendance_request');
    $e = worker();
    weeklyRoster($e, $w->pagi);
    $hr = actingInOrg($w->hr, $w->org);

    $hr->postJson('/attendance/bulk-mark', ['employee_ids' => [$e->id], 'from_date' => '2026-11-02', 'to_date' => '2026-11-03', 'attendance_status' => 'present', 'remarks' => 'Kursus'])
        ->assertOk()->assertJsonPath('created', 2);

    $user = User::query()->findOrFail($e->user_id);
    actingInOrg($user, $w->org)->post('/attendance-requests', ['kind' => 'wfh', 'from_date' => '2026-11-11', 'to_date' => '2026-11-11', 'explanation' => 'Rumah', 'submit' => true])
        ->assertRedirect();
    $id = AttendanceRequest::query()->value('id');
    actingInOrg($user, $w->org)->get('/attendance-requests/'.$id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/Requests/Show')->where('request.status', 'pending')->has('timeline')->has('preview', 1));

    rowFor($e, '2026-11-04', ['attendance_status' => 'present', 'worked_minutes' => 600, 'normal_minutes' => 480, 'ot_detected_minutes' => 120,
        'overtime_type_id' => OvertimeType::query()->value('id')]);
    actingInOrg($w->hr, $w->org)->postJson('/attendance/overtime-claims/generate', ['month' => '2026-11'])->assertOk()->assertJsonPath('generated', 1);
    $claim = OvertimeClaim::query()->firstOrFail();
    actingInOrg($w->hr, $w->org)->get('/attendance/overtime-claims/'.$claim->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Attendance/OvertimeClaims/Show')->has('claim.lines', 1)->where('claim.wage_missing', true));
});
