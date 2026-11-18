<?php

// spec-02 §6.1 jobs, §6.4 seed defaults and the EmployeeHired auto-assignment (§2.14).

use App\Domain\Core\Events\EmployeeHired;
use App\Domain\Leave\Actions\AllocationActions;
use App\Domain\Leave\Actions\PolicyAssignmentActions;
use App\Domain\Leave\Jobs\RolloverLeavePeriods;
use App\Domain\Leave\Jobs\SendLeaveExpiryReminders;
use App\Domain\Leave\Models\LeaveAggregateCap;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Notifications\LeaveExpiringSoon;
use App\Domain\Leave\Services\CarryForwardProcessor;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

it('seeds Akta Kerja defaults for a new organisation', function () {
    $w = leaveWorld('2026-06-01');
    $settings = LeaveSettings::current();
    $policy = LeavePolicy::query()->with('rows.tiers')->findOrFail($settings->default_leave_policy_id);

    expect(LeaveType::query()->count())->toBe(11)
        ->and(LeaveType::query()->where('is_active', true)->pluck('code')->sort()->values()->all())->toBe(['BSL', 'CGN', 'CTG', 'HOS', 'PTR', 'SKT', 'TAH'])
        ->and($policy->rows)->toHaveCount(3)
        ->and($policy->rows->firstWhere('leave_type_id', seededType('TAH')->id)->tiers->pluck('days')->map(fn ($d) => (string) $d)->all())->toBe(['8.000', '12.000', '16.000'])
        ->and((string) LeaveAggregateCap::query()->firstOrFail()->cap_days)->toBe('60.000')
        ->and(LeavePeriod::query()->where('name', '2026')->exists())->toBeTrue();
});

it('assigns the default policy on hire and generates allocations', function () {
    $w = leaveWorld('2026-03-01');
    $employee = staff(['date_of_joining' => '2026-03-01']);

    EmployeeHired::dispatch($employee);

    $assignment = LeavePolicyAssignment::query()->where('employee_id', $employee->id)->firstOrFail();
    $tah = LeaveAllocation::query()->where('employee_id', $employee->id)->where('leave_type_id', seededType('TAH')->id)->firstOrFail();
    expect($assignment->status)->toBe(DocumentStatus::Submitted)
        ->and(LeaveAllocation::query()->where('employee_id', $employee->id)->count())->toBe(3)
        ->and(app(LeaveLedger::class)->creditedEntitlement($tah))->toBe('7.000'); // 8 × 10/12 = 6.667 → 7
});

it('generates allocations for a new period and carries forward in the rollover job (idempotent)', function () {
    $w = leaveWorld('2026-12-01');
    $employee = staff();
    $actions = app(PolicyAssignmentActions::class);
    $actions->submit($actions->saveDraft(null, ['employee_id' => $employee->id, 'leave_policy_id' => LeaveSettings::current()->default_leave_policy_id, 'effective_from' => '2026-01-01']), null);
    LeavePeriod::factory()->year(2027)->create();

    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:10'));
    $run = fn () => (new RolloverLeavePeriods)->handle(app(AllocationActions::class), app(CarryForwardProcessor::class));
    $run();
    $run();

    $tah2027 = LeaveAllocation::query()->where('employee_id', $employee->id)->where('leave_type_id', seededType('TAH')->id)
        ->whereHas('period', fn ($q) => $q->where('name', '2027'))->get();
    expect($tah2027)->toHaveCount(1)
        ->and($tah2027[0]->carry_processed_at)->not->toBeNull()
        ->and(app(LeaveLedger::class)->creditedEntitlement($tah2027[0]))->toBe('16.000') // > 60 months of service
        ->and(LeavePeriod::query()->where('name', '2027')->value('allocations_generated_at'))->not->toBeNull();
});

it('reminds the employee once per lot about leave expiring soon', function () {
    Notification::fake();
    $w = leaveWorld('2026-12-05');
    $employee = staff();
    allocate($employee, leaveTypeMake(['code' => 'EXP']), '3');

    (new SendLeaveExpiryReminders)->handle(app(LeaveLedger::class));
    (new SendLeaveExpiryReminders)->handle(app(LeaveLedger::class));

    Notification::assertSentToTimes($employee->user, LeaveExpiringSoon::class, 1);
});
