<?php

// spec-02 §7 cases 8–14 and 44 (entitlement, pro-rata, tiers, accrual, policy change).

use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Leave\Actions\LeaveConfigActions;
use App\Domain\Leave\Actions\PolicyAssignmentActions;
use App\Domain\Leave\Enums\CreditScheduleKind;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Jobs\PostDueLeaveCredits;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyRow;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\CreditPoster;
use App\Domain\Leave\Services\EntitlementCalculator;
use App\Domain\Leave\Services\LeaveLedger;
use Carbon\CarbonImmutable;

function leavePolicyWith(LeaveType $type, array $row, string $name = 'Polisi Ujian'): LeavePolicy
{
    return app(LeaveConfigActions::class)->savePolicy(null, ['name' => $name, 'rows' => [['leave_type_id' => $type->id] + $row]]);
}

function leaveAssignPolicy(int $employeeId, LeavePolicy $policy, string $from): array
{
    $actions = app(PolicyAssignmentActions::class);
    $draft = $actions->saveDraft(null, ['employee_id' => $employeeId, 'leave_policy_id' => $policy->id, 'effective_from' => $from]);

    return $actions->submit($draft, null);
}

function leaveRowOf(LeavePolicy $policy): LeavePolicyRow
{
    return LeavePolicyRow::query()->where('leave_policy_id', $policy->id)->with(['tiers', 'leaveType'])->firstOrFail();
}

beforeEach(function () {
    $this->w = leaveWorld('2026-01-01');
    $this->annual = leaveTypeMake(['code' => 'ANL', 'proration_method' => 'completed_months', 'rounding_step' => '1']);
});

// #8, #9
it('pro-rates by completed months for joiners and rounds half-up to whole days', function (string $joined, string $expected) {
    $employee = staff(['date_of_joining' => $joined]);
    $policy = leavePolicyWith($this->annual, ['annual_days' => 8]);

    expect(app(EntitlementCalculator::class)->forRow($employee, leaveRowOf($policy), $this->w->period)->total)->toBe($expected);

    $this->travelTo(CarbonImmutable::parse($joined));
    leaveAssignPolicy($employee->id, $policy, $joined);
    $allocation = LeaveAllocation::query()->where('employee_id', $employee->id)->firstOrFail();
    expect(app(LeaveLedger::class)->creditedEntitlement($allocation))->toBe($expected);
})->with([
    'joins 16/03 → 9 months → 6' => ['2026-03-16', '6.000'],
    'joins 01/03 → 10 months → 6.667 → 7' => ['2026-03-01', '7.000'],
]);

// #10
it('splits a service-tier anniversary inside the period and schedules the uplift', function () {
    $employee = staff(['date_of_joining' => '2024-07-01']);
    $policy = leavePolicyWith($this->annual, ['uses_service_tiers' => true, 'tier_basis' => 'prorata_by_anniversary', 'tiers' => [
        ['min_service_months' => 0, 'days' => 8], ['min_service_months' => 24, 'days' => 12],
    ]]);

    $ent = app(EntitlementCalculator::class)->forRow($employee, leaveRowOf($policy), $this->w->period);
    expect($ent->total)->toBe('10.000')->and($ent->initial)->toBe('8.000');

    leaveAssignPolicy($employee->id, $policy, '2026-01-01');
    $allocation = LeaveAllocation::query()->where('employee_id', $employee->id)->firstOrFail();
    $schedule = LeaveCreditSchedule::query()->where('leave_allocation_id', $allocation->id)->get();

    expect(app(LeaveLedger::class)->creditedEntitlement($allocation))->toBe('8.000')
        ->and($schedule)->toHaveCount(1)
        ->and($schedule[0]->kind)->toBe(CreditScheduleKind::TierChange)
        ->and($schedule[0]->due_date->toDateString())->toBe('2026-07-01')
        ->and((string) $schedule[0]->days)->toBe('2.000');

    $this->travelTo(CarbonImmutable::parse('2026-07-01 00:15'));
    (new PostDueLeaveCredits)->handle(app(CreditPoster::class), app(HrRecipients::class));

    expect($schedule[0]->fresh()->status)->toBe(CreditScheduleStatus::Posted)
        ->and(app(LeaveLedger::class)->creditedEntitlement($allocation))->toBe('10.000');
});

// #11
it('uses one tier for the whole window with service_at_period_start / _end', function (string $basis, string $expected) {
    $employee = staff(['date_of_joining' => '2024-07-01']);
    $policy = leavePolicyWith($this->annual, ['uses_service_tiers' => true, 'tier_basis' => $basis, 'tiers' => [
        ['min_service_months' => 0, 'days' => 8], ['min_service_months' => 24, 'days' => 12],
    ]]);
    $ent = app(EntitlementCalculator::class)->forRow($employee, leaveRowOf($policy), $this->w->period);

    expect($ent->total)->toBe($expected)->and($ent->boundaries)->toBe([]);
})->with([
    'start' => ['service_at_period_start', '8.000'],
    'end' => ['service_at_period_end', '12.000'],
]);

// #12, #13
it('accrues earned leave monthly with cumulative half-up rounding and catches up missed runs', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-16'));
    $earned = leaveTypeMake(['code' => 'ERN', 'entitlement_basis' => 'earned', 'accrual_frequency' => 'monthly', 'accrual_timing' => 'sub_period_end', 'rounding_step' => '0.5']);
    $employee = staff(['date_of_joining' => '2026-03-16']);
    leaveAssignPolicy($employee->id, leavePolicyWith($earned, ['annual_days' => 18]), '2026-03-16');

    $allocation = LeaveAllocation::query()->where('employee_id', $employee->id)->firstOrFail();
    $rows = LeaveCreditSchedule::query()->where('leave_allocation_id', $allocation->id)->orderBy('due_date')->get();
    expect($rows->take(3)->map(fn ($r) => [$r->due_date->toDateString(), (string) $r->days])->all())->toBe([
        ['2026-03-31', '1.000'], ['2026-04-30', '1.500'], ['2026-05-31', '1.500'],
    ])->and(number_format((float) $rows->sum('days'), 3))->toBe('14.500')
        ->and((string) $allocation->entitlement_days)->toBe('14.500');

    $run = fn () => (new PostDueLeaveCredits)->handle(app(CreditPoster::class), app(HrRecipients::class));

    $this->travelTo(CarbonImmutable::parse('2026-04-01'));
    $run();
    // job did not run 30/04–31/05; on 01/06 both rows post with their own dates
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $run();
    $accruals = LeaveMovement::query()->where('leave_allocation_id', $allocation->id)->where('kind', MovementKind::Accrual->value)->orderBy('id')->get();
    expect($accruals->map(fn ($m) => [$m->posted_on->toDateString(), (string) $m->days])->all())->toBe([
        ['2026-03-31', '1.000'], ['2026-04-30', '1.500'], ['2026-05-31', '1.500'],
    ]);

    $run();
    expect(LeaveMovement::query()->where('leave_allocation_id', $allocation->id)->where('kind', MovementKind::Accrual->value)->count())->toBe(3);
});

// #14
it('skips scheduled credits after the relieving date on separation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-16'));
    $earned = leaveTypeMake(['code' => 'ERN', 'entitlement_basis' => 'earned', 'accrual_frequency' => 'monthly', 'accrual_timing' => 'sub_period_end', 'rounding_step' => '0.5']);
    $employee = staff(['date_of_joining' => '2026-03-16']);
    leaveAssignPolicy($employee->id, leavePolicyWith($earned, ['annual_days' => 18]), '2026-03-16');

    $employee->update(['relieving_date' => '2026-05-15']);
    EmployeeSeparated::dispatch($employee->refresh());

    $allocation = LeaveAllocation::query()->where('employee_id', $employee->id)->firstOrFail();
    $statuses = LeaveCreditSchedule::query()->where('leave_allocation_id', $allocation->id)->orderBy('due_date')->pluck('status', 'due_date')
        ->mapWithKeys(fn ($s, $d) => [substr((string) $d, 0, 10) => $s->value])->all();

    expect($statuses['2026-03-31'])->toBe('pending')
        ->and($statuses['2026-04-30'])->toBe('pending')
        ->and($statuses['2026-05-31'])->toBe('skipped')
        ->and($statuses['2026-12-31'])->toBe('skipped');
});

// #44
it('posts a policy_change credit when the policy changes mid-period', function () {
    $employee = staff(['date_of_joining' => '2020-01-01']);
    leaveAssignPolicy($employee->id, leavePolicyWith($this->annual, ['annual_days' => 8], 'Lapan'), '2026-01-01');

    $this->travelTo(CarbonImmutable::parse('2026-07-01'));
    leaveAssignPolicy($employee->id, leavePolicyWith($this->annual, ['annual_days' => 12], 'Dua Belas'), '2026-07-01');

    $allocation = LeaveAllocation::query()->where('employee_id', $employee->id)->where('leave_type_id', $this->annual->id)->live()->firstOrFail();
    $change = LeaveMovement::query()->where('leave_allocation_id', $allocation->id)->where('kind', MovementKind::PolicyChange->value)->get();

    expect($change)->toHaveCount(1)
        ->and((string) $change[0]->days)->toBe('2.000')
        ->and($change[0]->date_from->toDateString())->toBe('2026-07-01')
        ->and(app(LeaveLedger::class)->creditedEntitlement($allocation))->toBe('10.000')
        ->and(LeaveAllocation::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
