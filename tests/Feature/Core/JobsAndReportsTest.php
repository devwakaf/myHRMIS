<?php

use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Jobs\SendEmploymentReminders;
use App\Domain\Core\Jobs\SendPeopleDigest;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Notifications\CoreDigestNotification;
use App\Domain\Core\Reports\EmployeeAnalyticsReport;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Core\Services\WorkHistoryRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(7, 0));
    $this->org = inOrg(makeOrganisation());
    $this->hr = userIn($this->org, 'pengurus_hr');
});

// B8 #66
it('sends employment reminders once per item per threshold', function () {
    Notification::fake();
    employee(['probation_end_date' => '2026-06-20', 'confirmed_on' => null]);
    employee(['contract_end_date' => '2026-07-01']);
    employee(['date_of_joining' => '2026-01-05', 'probation_end_date' => '2026-12-31']);

    (new SendEmploymentReminders)->handle(app(HrRecipients::class));
    (new SendEmploymentReminders)->handle(app(HrRecipients::class));

    Notification::assertSentToTimes($this->hr, CoreDigestNotification::class, 1);
    Notification::assertSentTo($this->hr, CoreDigestNotification::class, fn ($n) => count($n->lines) === 2 && $n->kind === 'employment_reminders');
});

// B8 #67
it('keeps the birthday digest away from colleagues by default and hides years in the report', function () {
    Notification::fake();
    $colleague = userIn($this->org, 'kakitangan');
    employee(['ic_no' => null, 'identity_type' => 'passport', 'citizenship_status' => 'foreign', 'nationality' => 'ID',
        'passport_no' => 'A1234567', 'passport_country' => 'ID', 'date_of_birth' => '1990-06-15'], $colleague);

    (new SendPeopleDigest)->handle(app(HrRecipients::class));
    Notification::assertSentTo($this->hr, CoreDigestNotification::class);
    Notification::assertNotSentTo($colleague, CoreDigestNotification::class);

    $kktReader = userIn($this->org, 'pegawai_payroll'); // has core.reports.view and view-sensitive
    actingInOrg($this->hr, $this->org)->get('/reports/core/birthdays?month=6')->assertInertia(fn ($p) => $p->where('rows.0.birthday', '15/06')->has('rows.0.date_of_birth'));

    $role = Role::query()->create(['name' => 'laporan', 'guard_name' => 'web', 'organisation_id' => $this->org->id]);
    $role->givePermissionTo('core.reports.view');
    $reader = userIn($this->org, 'laporan');
    actingInOrg($reader, $this->org)->get('/reports/core/birthdays?month=6')
        ->assertInertia(fn ($p) => $p->where('rows.0.birthday', '15/06')->missing('rows.0.date_of_birth')->missing('rows.0.age'));
});

// B8 #68, #61f
it('uses work history for "as at" analytics and the service start for tenure bands', function () {
    $old = Department::factory()->create(['name' => 'Lama']);
    $new = Department::factory()->create(['name' => 'Baharu']);
    $e = employee(['department_id' => $old->id, 'date_of_joining' => '2025-01-06', 'continuous_service_from' => '2010-01-01']);
    $e->update(['department_id' => $new->id]);
    app(WorkHistoryRecorder::class)->transition($e, CarbonImmutable::parse('2026-03-01'), WorkHistorySource::Movement);

    $past = app(EmployeeAnalyticsReport::class)->build(['as_at' => '2025-06-01', 'dimension' => 'department']);
    $now = app(EmployeeAnalyticsReport::class)->build(['as_at' => '2026-06-01', 'dimension' => 'department']);
    $tenure = app(EmployeeAnalyticsReport::class)->build(['as_at' => '2026-06-01', 'dimension' => 'tenure_band']);

    expect($past['rows'][0]['label'])->toBe('Lama')
        ->and($now['rows'][0]['label'])->toBe('Baharu')
        ->and($tenure['rows'][0]['label'])->toBe(__('core.reports.tenure.gt10'));
});

it('renders and exports every core report', function () {
    employee();
    actingInOrg($this->hr, $this->org);
    foreach (['employees', 'analytics', 'birthdays', 'leavers', 'upcoming', 'org-chart'] as $report) {
        $this->get("/reports/core/{$report}")->assertOk()->assertInertia(fn ($p) => $p->component('Core/Reports/Show')->where('report', $report));
        if ($report !== 'org-chart') {
            $this->get("/reports/core/{$report}/export?format=csv")->assertOk();
        }
    }
    $this->get('/reports/core/employees?columns[]=employee_no&columns[]=full_name&as_at=2026-01-01')->assertOk();
});
