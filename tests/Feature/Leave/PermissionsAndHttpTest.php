<?php

// spec-02 §7 case 45 (permissions & privacy) + HTTP wiring of the M2 routes.

use App\Domain\Leave\Actions\LeaveConfigActions;
use App\Domain\Leave\Actions\SubmitLeaveApplication;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveApplicationAttachment;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Services\LeaveCalendar;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->w = leaveWorld('2026-06-01');
    $this->employee = staff();
    $this->type = leaveTypeMake(['code' => 'TST']);
    allocate($this->employee, $this->type, '10');
});

it('lets HR configure leave and refuses a Kakitangan', function () {
    actingInOrg($this->w->hr, $this->w->org)->get('/leave/types')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Leave/Types/Index')->has('types'));
    actingInOrg($this->employee->user, $this->w->org)->get('/leave/types')->assertForbidden();
    actingInOrg($this->employee->user, $this->w->org)->get('/leave/applications')->assertForbidden();
    actingInOrg($this->employee->user, $this->w->org)->get('/leave/allocations')->assertForbidden();
    actingInOrg($this->employee->user, $this->w->org)->post('/leave/types', ['code' => 'X', 'name' => 'X', 'entitlement_basis' => 'per_period', 'pay_mode' => 'paid'])->assertForbidden();
});

it('renders every M2 page for HR', function (string $url, string $component) {
    actingInOrg($this->w->hr, $this->w->org)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/leave/applications', 'Leave/Applications/Index'],
    ['/leave/applications/create', 'Leave/Applications/Create'],
    ['/leave/calendar', 'Leave/Calendar'],
    ['/leave/compensatory-requests', 'Leave/Compensatory/Index'],
    ['/leave/periods', 'Leave/Periods/Index'],
    ['/leave/policies', 'Leave/Policies/Index'],
    ['/leave/policy-assignments', 'Leave/PolicyAssignments/Index'],
    ['/leave/allocations', 'Leave/Allocations/Index'],
    ['/leave/adjustments', 'Leave/Adjustments/Index'],
    ['/leave/encashments', 'Leave/Encashments/Index'],
    ['/leave/credit-schedules', 'Leave/CreditSchedules/Index'],
    ['/leave/aggregate-caps', 'Leave/AggregateCaps/Index'],
    ['/leave/block-lists', 'Leave/BlockLists/Index'],
    ['/leave/review-tasks', 'Leave/ReviewTasks/Index'],
    ['/settings/leave', 'Leave/Settings'],
]);

it('lets a Kakitangan apply through HTTP, preview, see Cuti Saya and the application', function () {
    actingInOrg($this->employee->user, $this->w->org);

    $preview = $this->postJson('/leave/applications/preview', ['leave_type_id' => $this->type->id, 'from_date' => '2026-06-08', 'to_date' => '2026-06-10'])
        ->assertOk()->json();
    expect($preview['breakdown']['total_days'])->toBe('3.000')
        ->and($preview['balance']['after'])->toBe('7.000')
        ->and($preview['errors'])->toBe([]);

    $this->post('/leave/applications', ['leave_type_id' => $this->type->id, 'from_date' => '2026-06-08', 'to_date' => '2026-06-10', 'submit' => true])
        ->assertRedirect();
    $app = LeaveApplication::query()->latest('id')->firstOrFail();
    expect($app->status)->toBe(ApprovalStatus::Pending);

    $this->get('/leave/me')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Leave/Me')->has('balances')->has('applications', 1));
    $this->get("/leave/applications/{$app->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Leave/Applications/Show')
        ->where('application.total_days', '3.000')->where('can.withdraw', true));

    $other = staff();
    actingInOrg($other->user, $this->w->org)->get("/leave/applications/{$app->id}")->assertForbidden();
});

it('restricts MC downloads to the applicant, HR and assigned approvers', function () {
    $app = draftLeave($this->employee, $this->type, '2026-06-08');
    actingInOrg($this->employee->user, $this->w->org)
        ->post("/leave/applications/{$app->id}/attachments", ['file' => UploadedFile::fake()->create('mc.pdf', 100, 'application/pdf'), 'kind' => 'mc'])
        ->assertRedirect();
    $attachment = LeaveApplicationAttachment::query()->firstOrFail();
    app(SubmitLeaveApplication::class)->submit($app, $this->employee->user);

    $outsider = userIn($this->w->org, ['kakitangan', 'pelulus']);
    $url = "/leave/attachments/{$attachment->id}/download";

    actingInOrg($outsider, $this->w->org)->get($url)->assertForbidden();
    actingInOrg($this->w->approver, $this->w->org)->get($url)->assertOk();
    actingInOrg($this->employee->user, $this->w->org)->get($url)->assertOk();
    actingInOrg($this->w->hr, $this->w->org)->get($url)->assertOk();
});

it('shows no colleagues on a Kakitangan calendar unless the setting allows names', function () {
    $colleague = staff(['department_id' => $this->employee->department_id]);
    allocate($colleague, $this->type, '5');
    approveLeave(applyLeave($colleague, $this->type, '2026-06-09'), $this->w->approver);
    applyLeave($this->employee, $this->type, '2026-06-10');
    $range = new DateRange('2026-06-01', '2026-06-30');

    $entries = app(LeaveCalendar::class)->entries($this->employee->user, $this->w->org->id, $range);
    expect($entries)->toHaveCount(1)->and($entries[0]['own'])->toBeTrue();

    LeaveSettings::current()->update(['calendar_colleague_visibility' => 'department_names_only']);
    $entries = app(LeaveCalendar::class)->entries($this->employee->user, $this->w->org->id, $range);
    $colleagueRow = collect($entries)->firstWhere('own', false);
    expect($entries)->toHaveCount(2)
        ->and($colleagueRow['type_code'])->toBeNull()
        ->and($colleagueRow['application_id'])->toBeNull();

    expect(app(LeaveCalendar::class)->entries($this->w->hr, $this->w->org->id, $range))->toHaveCount(2);
});

it('validates leave type rules from the spec', function () {
    $actions = app(LeaveConfigActions::class);

    expect(fn () => $actions->saveType(null, ['code' => 'U1', 'name' => 'U', 'entitlement_basis' => 'per_period', 'pay_mode' => 'unpaid']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $actions->saveType(null, ['code' => 'U2', 'name' => 'U', 'entitlement_basis' => 'unlimited', 'pay_mode' => 'paid', 'allow_carry_forward' => true]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $actions->saveType(null, ['code' => 'U3', 'name' => 'U', 'entitlement_basis' => 'earned', 'pay_mode' => 'paid']))
        ->toThrow(ValidationException::class)
        ->and(fn () => $actions->saveType(seededType('TAH'), ['code' => 'TAHUNAN']))
        ->toThrow(ValidationException::class);

    approveLeave(applyLeave($this->employee, $this->type, '2026-06-08'), $this->w->approver);
    expect(fn () => $actions->saveType($this->type, ['pay_mode' => 'partially_paid', 'paid_fraction' => '0.5']))
        ->toThrow(ValidationException::class);
});
