<?php

// M2 UI: every Cuti page renders (Inertia component + key props) for the right roles,
// the report screens and exports, the Kakitangan "Cuti" tab data, the Kelulusan Saya
// leave section and the demo data seeder.

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Actions\AllocationActions;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveBlockList;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeaveReviewTask;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Shared\Organisation\OrganisationContext;
use Database\Seeders\DemoLeaveSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\LeaveDefaultsSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->w = leaveWorld('2026-06-01', ['2026-06-10' => 'Cuti Contoh']);
    $this->employee = staff();
    $this->type = leaveTypeMake(['code' => 'TST', 'allow_encashment' => true]);
    $this->allocation = allocate($this->employee, $this->type, '10');
    $this->hr = fn () => actingInOrg($this->w->hr, $this->w->org);
    $this->self = fn () => actingInOrg($this->employee->user, $this->w->org);
});

it('renders Cuti Saya, Mohon Cuti, Kalendar Cuti and Cuti Gantian for a Kakitangan', function () {
    $application = applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-09');
    ($this->self)();

    $this->get('/leave/me')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Me')
        ->where('employee.id', $this->employee->id)
        ->has('balances')
        ->where('balances', fn ($balances) => collect($balances)->contains(fn ($b) => $b['type']['code'] === 'TST' && $b['available'] === '8.000'))
        ->has('applications', 1)
        ->has('compensatory_requests'));

    $this->get('/leave/applications/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Applications/Create')
        ->has('options.types')
        ->has('options.sessions', 2)
        ->where('can.create_for_others', false)
        ->where('own_employee_id', $this->employee->id)
        ->has('settings.default_max_backdate_days'));

    $this->get('/leave/calendar?month=2026-06-01')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Calendar')
        ->where('month', '2026-06')
        ->has('entries', 1)
        ->where('entries.0.application_id', $application->id)
        ->where('entries.0.status', 'pending')
        ->where('holidays', fn ($h) => collect($h)->contains(fn ($x) => $x['date'] === '2026-06-10' && $x['name'] === 'Cuti Contoh'))
        ->has('block_dates'));

    $this->get('/leave/compensatory-requests')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Compensatory/Index')->where('can.request', true)->has('requests.data'));
});

it('shows block-list dates on the calendar', function () {
    $list = LeaveBlockList::query()->create(['name' => 'Akhir Tahun', 'applies_to_all_departments' => true, 'is_active' => true]);
    $list->dates()->create(['date' => '2026-06-15', 'reason' => 'Penutup bulan']);

    ($this->self)()->get('/leave/calendar?month=2026-06-01')->assertInertia(fn (Assert $page) => $page
        ->where('block_dates', fn ($d) => count($d) === 1 && $d[0]['date'] === '2026-06-15' && $d[0]['list'] === 'Akhir Tahun'));
});

it('renders the application detail with permitted actions and the HR list', function () {
    $draft = draftLeave($this->employee, $this->type, '2026-06-22');
    ($this->self)()->get("/leave/applications/{$draft->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Applications/Show')
        ->where('application.status', 'draft')
        ->has('application.days')
        ->where('can.update', true)->where('can.submit', true)->where('can.withdraw', false));

    ($this->hr)()->get('/leave/applications?status=draft')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Applications/Index')
        ->has('applications.data', 1)
        ->where('filters.status', 'draft')
        ->has('options.types')->has('options.statuses')
        ->where('can.create_for_others', true));
});

it('renders the Jenis Cuti, Tempoh, Leave Policy, caps, block list and settings pages for HR', function () {
    ($this->hr)();

    $this->get('/leave/types')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Types/Index')
        ->where('types', fn ($t) => collect($t)->contains(fn ($x) => $x['code'] === 'TAH' && isset($x['display_name'], $x['entitlement_basis_label'])))
        ->has('options.entitlement_bases')->has('options.proration_methods')->has('options.accrual_timings')
        ->where('can.manage', true));

    $this->get('/leave/periods')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Periods/Index')->has('periods')->where('can.manage', true)->where('can.generate', true));

    $this->get('/leave/policies')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Policies/Index')
        ->where('policies', fn ($p) => collect($p)->contains(fn ($x) => collect($x['rows'])->contains(fn ($r) => $r['uses_service_tiers'] && count($r['tiers']) === 3)))
        ->has('options.types')->has('options.tier_bases'));

    $this->get('/leave/aggregate-caps')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/AggregateCaps/Index')->has('caps', 1)->has('options.types'));

    $this->get('/leave/block-lists')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/BlockLists/Index')->has('lists')->has('options.departments')->has('options.users'));

    $this->get('/settings/leave')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Settings')
        ->has('settings.default_leave_policy_id')
        ->has('options.calendar_visibilities')->has('options.encashment_rate_bases')->has('options.applicant_cancel_rules'));
});

it('renders the ledger screens for HR with the extra pickers', function () {
    ($this->hr)();

    $this->get('/leave/policy-assignments')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/PolicyAssignments/Index')
        ->has('assignments.data')->has('options.employees')->has('options.policies')
        ->has('options.branches')->has('options.departments')->has('options.employment_types'));

    $this->get('/leave/allocations?employee_id='.$this->employee->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Allocations/Index')->has('allocations.data', 1)->has('options.periods')->has('options.types'));

    $this->get("/leave/allocations/{$this->allocation->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Allocations/Show')
        ->where('allocation.id', $this->allocation->id)
        ->has('allocation.lots', 1)->where('allocation.lots.0.remaining', '10.000')
        ->has('allocation.ledger')->has('allocation.credit_schedules'));

    $this->get('/leave/adjustments')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Adjustments/Index')
        ->has('adjustments.data')->where('can.submit', true)
        ->where('options.allocations', fn ($a) => collect($a)->contains(fn ($x) => $x['value'] === $this->allocation->id)));

    $this->get('/leave/encashments')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Encashments/Index')
        ->has('encashments.data')->where('can.manage', true)
        ->where('options.allocations', fn ($a) => collect($a)->contains(fn ($x) => $x['value'] === $this->allocation->id))
        ->has('options.rate_bases')->has('options.purposes')->where('options.default_rate_basis', 'org_daily_rate'));

    $this->get('/leave/credit-schedules')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/CreditSchedules/Index')->has('schedules.data'));

    $application = applyLeave($this->employee, $this->type, '2026-06-08');
    LeaveReviewTask::query()->create(['leave_application_id' => $application->id, 'reason' => 'holiday_removed', 'details' => []]);
    $this->get('/leave/review-tasks')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/ReviewTasks/Index')
        ->has('tasks.data', 1)->where('tasks.data.0.reason_label', fn ($label) => $label !== 'leave.review_reasons.holiday_removed')
        ->where('tasks.data.0.application.id', $application->id));
});

it('keeps the HR screens closed to a Kakitangan', function (string $url) {
    ($this->self)()->get($url)->assertForbidden();
})->with([
    '/leave/types', '/leave/periods', '/leave/policies', '/leave/aggregate-caps', '/leave/block-lists', '/settings/leave',
    '/leave/policy-assignments', '/leave/allocations', '/leave/adjustments', '/leave/credit-schedules', '/leave/review-tasks',
    '/leave/applications', '/reports/leave/balances', '/reports/leave/unpaid',
]);

it('runs the bulk assignment as JSON preview and result list', function () {
    $other = staff(['date_of_joining' => '2025-03-01']);
    ($this->hr)();
    $policy = LeavePolicy::query()->firstOrFail();

    $payload = ['leave_policy_id' => $policy->id, 'effective_from' => '2026-01-01', 'bulk' => true, 'employee_ids' => [$this->employee->id, $other->id]];
    $preview = $this->postJson('/leave/policy-assignments', $payload + ['preview' => true])->assertOk()->json('results');
    expect($preview)->toHaveCount(2)->and(collect($preview)->every(fn ($r) => $r['ok']))->toBeTrue()
        ->and(LeavePolicyAssignment::query()->where('leave_policy_id', $policy->id)->whereIn('employee_id', [$this->employee->id, $other->id])->count())->toBe(0);

    $results = $this->postJson('/leave/policy-assignments', $payload)->assertOk()->json('results');
    expect($results)->toHaveCount(2);
    foreach ($results as $row) {
        expect($row)->toHaveKeys(['employee_id', 'employee', 'ok', 'message']);
    }
});

it('returns the encashment preview as JSON for the calculator', function () {
    ($this->hr)();
    $json = $this->postJson('/leave/encashments/preview', [
        'leave_allocation_id' => $this->allocation->id, 'encashment_date' => '2026-06-01', 'days' => '2', 'monthly_basic' => '2600', 'rate_basis' => 'orp_26',
    ])->assertOk()->json();

    expect($json)->toHaveKeys(['eligible_days', 'rate'])
        ->and($json['rate']['daily_rate'])->toBe('100.000000')
        ->and($json['rate']['amount'])->toBe('200.00');
});

it('renders the leave reports for HR and exports them without attachments', function (string $report) {
    applyLeave($this->employee, $this->type, '2026-06-08');
    ($this->hr)();

    $this->get("/reports/leave/{$report}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Reports/Show')
        ->where('report', $report)
        ->where('reports', fn ($r) => count($r) === 6)
        ->has('columns')->has('rows')->has('options.employees')->has('options.types')->has('options.kinds')
        ->where('columns', fn ($cols) => collect($cols)->every(fn ($c) => ! preg_match('/attach|mc_|reason|diagnos/i', $c['key']))));

    foreach (['xlsx', 'csv'] as $format) {
        $response = $this->get("/reports/leave/{$report}/export?format={$format}")->assertOk();
        expect($response->headers->get('content-disposition'))->toContain("laporan-cuti-{$report}")->toContain(".{$format}");
    }
})->with(['balances', 'ledger', 'summary', 'unpaid', 'caps', 'negative']);

it('computes balances and ledger rows on the report screens', function () {
    ($this->hr)();

    $this->get('/reports/leave/balances?from=2026-01-01&to=2026-06-01&employee_id='.$this->employee->id)->assertInertia(fn (Assert $page) => $page
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['leave_type'] === $this->type->displayName() && $r['credited'] === '10.000' && $r['closing'] === '10.000')));

    $this->get('/reports/leave/ledger?leave_type_id='.$this->type->id)->assertInertia(fn (Assert $page) => $page
        ->where('rows', fn ($rows) => count($rows) >= 1 && collect($rows)->every(fn ($r) => $r['leave_type'] === $this->type->displayName()))
        ->where('truncated', false));

    $this->get('/reports/leave/summary?date=2026-06-01')->assertInertia(fn (Assert $page) => $page
        ->where('columns', fn ($c) => collect($c)->contains(fn ($x) => $x['key'] === 'type_'.$this->type->id))
        ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => ($r['type_'.$this->type->id] ?? null) === '10.000')));
});

it('limits Payroll to the unpaid-leave report', function () {
    $po = userIn($this->w->org, ['pegawai_payroll']);
    actingInOrg($po, $this->w->org);

    $this->get('/reports/leave/unpaid?month=2026-06')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Leave/Reports/Show')->where('reports', ['unpaid'])->has('columns', 4));
    $this->get('/reports/leave/balances')->assertForbidden();
    $this->get('/reports/leave/ledger/export')->assertForbidden();
});

it('lists unpaid days in the CTG report', function () {
    $ctg = seededType('CTG');
    applyLeave($this->employee, $ctg, '2026-06-08', '2026-06-09', ['reason' => 'Urusan peribadi']);
    ($this->hr)();

    $this->get('/reports/leave/unpaid?month=2026-06')->assertInertia(fn (Assert $page) => $page
        ->where('rows', fn ($rows) => count($rows) === 1 && $rows[0]['pending_unpaid_days'] === '2.000' && $rows[0]['unpaid_days'] === '0.000'));
});

it('exposes the Kakitangan Cuti tab through the employee page and the balances endpoint', function () {
    ($this->hr)()->get("/employees/{$this->employee->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Core/Employees/Show')->where('can.leave', true));
    ($this->self)()->get("/employees/{$this->employee->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.leave', true));

    $other = staff();
    actingInOrg($other->user, $this->w->org)->get("/leave/employees/{$this->employee->id}/balances")->assertForbidden();
    ($this->hr)()->getJson("/leave/employees/{$this->employee->id}/balances")->assertOk()
        ->assertJsonPath('balances.0.type.id', fn ($id) => is_int($id))
        ->assertJsonStructure(['date', 'balances' => [['type', 'available', 'reserved', 'expiring', 'caps']]]);
});

it('renders the leave decision context for the approver in Kelulusan Saya', function () {
    $application = applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-09', ['reason' => 'Urusan keluarga']);
    $requestId = $application->approvalRequest()->firstOrFail()->id;

    actingInOrg($this->w->approver, $this->w->org)->get('/approvals?request='.$requestId)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Approvals/Index')
        ->where('selected.type', 'leave')
        ->where('selected.decision_context.total_days', '2.000')
        ->where('selected.decision_context.balance_after', '8.000')
        ->where('selected.decision_context.type.code', 'TST')
        ->has('selected.decision_context.days', 2)
        ->has('selected.decision_context.block_list_hits')
        ->where('selected.can.decide', true));
});

it('shows the Cuti navigation entries only to users who may use them', function () {
    // Sidebar visibility is decided in the browser from auth.abilities; verify the abilities it relies on.
    $abilities = fn ($user) => actingInOrg($user, $this->w->org)->get('/dashboard')->assertOk()->viewData('page')['props']['auth']['abilities'];

    expect($abilities($this->w->hr))->toContain('leave.config.manage', 'leave.allocation.manage', 'leave.reports.view')
        ->and($abilities($this->employee->user))->not->toContain('leave.config.manage', 'leave.allocation.manage', 'leave.reports.view');
});

it('seeds demo policy assignments and sample applications in every state', function () {
    $this->travelBack();
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->seed(LeaveDefaultsSeeder::class);
    $this->seed(DemoLeaveSeeder::class);
    $this->seed(DemoLeaveSeeder::class); // idempotent

    $pahang = Organisation::query()->where('short_code', 'SDT')->firstOrFail();
    OrganisationContext::run($pahang, function () {
        $employees = Employee::query()->count();
        $assigned = LeavePolicyAssignment::query()->where('status', 'submitted')->distinct('employee_id')->count('employee_id');
        expect($assigned)->toBe($employees)
            ->and(LeaveAllocation::query()->count())->toBeGreaterThan($employees);

        $states = LeaveApplication::query()->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values()->all();
        expect($states)->toBe(['approved', 'cancelled', 'draft', 'pending', 'rejected']);
        expect(LeaveType::query()->where('code', 'TAH')->exists())->toBeTrue();
    });
});

it('keeps allocations usable through the manual allocation action used by the UI', function () {
    // The Peruntukan Manual dialog posts AllocationRequest; make sure the saved draft submits cleanly.
    ($this->hr)();
    $other = staff();
    $this->post('/leave/allocations', [
        'employee_id' => $other->id, 'leave_type_id' => $this->type->id, 'leave_period_id' => $this->w->period->id, 'initial_days' => '4', 'submit' => true,
    ])->assertRedirect();

    $allocation = LeaveAllocation::query()->where('employee_id', $other->id)->firstOrFail();
    expect($allocation->status->value)->toBe('submitted')
        ->and(app(AllocationActions::class))->not->toBeNull();
});
