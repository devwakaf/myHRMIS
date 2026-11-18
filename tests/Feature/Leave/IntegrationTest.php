<?php

// spec-02 §7 cases 36–43 (Payroll / Attendance ports, encashment, holiday changes, separation, Cuti Gantian).

use App\Domain\Core\Actions\SaveHoliday;
use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Actions\CancelLeaveApplication;
use App\Domain\Leave\Actions\CompensatoryRequestActions;
use App\Domain\Leave\Actions\EncashmentActions;
use App\Domain\Leave\Actions\LeaveConfigActions;
use App\Domain\Leave\Actions\PolicyAssignmentActions;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Leave\Enums\EncashmentRateBasis;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeaveReviewTask;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\SpyAttendancePort;

beforeEach(function () {
    $this->w = leaveWorld('2026-06-01');
    $this->spy = SpyAttendancePort::install();
    $this->employee = staff();
    $this->unpaid = app(UnpaidLeaveQuery::class);
    $this->june = new DateRange('2026-06-01', '2026-06-30');
});

function leaveLockJune(): void
{
    app()->instance(PayrollLockQuery::class, new class implements PayrollLockQuery
    {
        public function isLocked(Employee|int $employee, CarbonImmutable $date): bool
        {
            return $date->month === 6 && $date->year === 2026;
        }

        public function lockedDatesIn(Employee|int $employee, DateRange $range): array
        {
            return array_values(array_filter(iterator_to_array($range->dates(), false), fn ($d) => $this->isLocked($employee, $d)));
        }
    });
}

// #36
it('reports approved unpaid days per pay period, split across months', function () {
    $ctg = seededType('CTG');
    approveLeave(applyLeave($this->employee, $ctg, '2026-06-15', '2026-06-19', ['reason' => 'Urusan']), $this->w->approver);
    $pending = applyLeave($this->employee, $ctg, '2026-06-29', '2026-07-03', ['reason' => 'Urusan']);

    expect($this->unpaid->unpaidDays($this->employee, $this->june)->total)->toBe('5.000')
        ->and($this->unpaid->pendingUnpaidDays($this->employee, $this->june))->toBe('2.000');

    approveLeave($pending, $this->w->approver);
    expect($this->unpaid->unpaidDays($this->employee, $this->june)->total)->toBe('7.000')
        ->and($this->unpaid->unpaidDays($this->employee, new DateRange('2026-07-01', '2026-07-31'))->total)->toBe('3.000')
        ->and($this->unpaid->unpaidDays($this->employee, $this->june)->items[0])->toMatchArray(['date' => '2026-06-15', 'portion' => '1.000', 'unpaid_portion' => '1.000']);
});

// #37
it('applies one definition of unpaid share for partially paid leave', function () {
    $ppl = app(LeaveConfigActions::class)->saveType(null, ['code' => 'PPL', 'name' => 'Cuti Separuh Gaji', 'entitlement_basis' => 'unlimited', 'pay_mode' => 'partially_paid', 'paid_fraction' => '0.5', 'is_active' => true]);

    $four = approveLeave(applyLeave($this->employee, $ppl, '2026-06-08', '2026-06-11'), $this->w->approver);
    $half = approveLeave(applyLeave($this->employee, $ppl, '2026-06-15', null, ['half_day_date' => '2026-06-15', 'half_day_session' => 'am']), $this->w->approver);

    expect((string) $four->unpaid_days)->toBe('2.000')
        ->and((string) $half->unpaid_days)->toBe('0.250')
        ->and($this->unpaid->unpaidDays($this->employee, $this->june)->total)->toBe('2.250');
});

// #38
it('refuses approving or cancelling unpaid leave in a locked payroll period and warns for paid leave', function () {
    $ctg = seededType('CTG');
    $toApprove = applyLeave($this->employee, $ctg, '2026-06-15', null, ['reason' => 'x']);
    $toCancel = approveLeave(applyLeave($this->employee, $ctg, '2026-06-16', null, ['reason' => 'x']), $this->w->approver);
    $paid = leaveTypeMake(['code' => 'PD']);
    allocate($this->employee, $paid, '5');

    leaveLockJune();

    expect(fn () => app(ApprovalService::class)->approve($toApprove->approvalRequest()->firstOrFail(), $this->w->approver))->toThrow(ApprovalCompletionBlocked::class)
        ->and(fn () => app(CancelLeaveApplication::class)->cancelApproved($toCancel, $this->w->hr, 'x'))->toThrow(LeaveRuleViolation::class)
        ->and(fn () => applyLeave($this->employee, $ctg, '2026-06-22', null, ['reason' => 'x']))->toThrow(ValidationException::class);

    $leave = applyLeave($this->employee, $paid, '2026-06-23');
    expect($this->leaveWarnings)->toHaveCount(1);
    expect(approveLeave($leave, $this->w->approver)->status)->toBe(ApprovalStatus::Approved);
});

// #39
it('computes the encashment amount for every rate basis', function (string $basis, ?EncashmentRateBasis $rateBasis, string $daily, string $amount) {
    Organisation::query()->whereKey($this->w->org->id)->update(['daily_rate_basis' => $basis, 'daily_rate_divisor' => '26']);
    $rate = app(EncashmentActions::class)->rate($this->employee->refresh(), '3250.00', '5', $rateBasis ?? EncashmentRateBasis::OrgDailyRate, CarbonImmutable::parse('2026-06-15'));

    expect($rate['daily_rate'])->toBe($daily)->and($rate['amount'])->toBe($amount);
})->with([
    'fixed ÷26' => ['fixed_divisor', null, '125.000000', '625.00'],
    'calendar days (June)' => ['calendar_days', null, '108.333333', '541.67'],
    'working days (June, 22)' => ['working_days', null, '147.727273', '738.64'],
    'orp ÷26' => ['calendar_days', EncashmentRateBasis::Orp26, '125.000000', '625.00'],
]);

// #40
it('limits encashable days by reserve and maximum', function () {
    $type = leaveTypeMake(['code' => 'ENC', 'allow_encashment' => true, 'encashment_reserve_days' => '2', 'max_encashable_days' => '3']);
    $allocation = allocate($this->employee, $type, '6');
    $actions = app(EncashmentActions::class);

    expect($actions->eligibleDays($allocation->load('leaveType'), CarbonImmutable::parse('2026-06-15')))->toBe('3.000')
        ->and(fn () => $actions->saveDraft(null, ['leave_allocation_id' => $allocation->id, 'encashment_date' => '2026-06-15', 'days' => '4', 'monthly_basic' => '3000']))
        ->toThrow(ValidationException::class);

    $draft = $actions->saveDraft(null, ['leave_allocation_id' => $allocation->id, 'encashment_date' => '2026-06-15', 'days' => '3', 'monthly_basic' => '2600']);
    $actions->submit($draft['encashment'], $this->w->hr);
    expect((string) $draft['encashment']->fresh()->amount)->toBe('300.00')
        ->and(app(LeaveBalance::class)->on($this->employee, $type, '2026-06-15')['available'])->toBe('3.000');
});

// #41
it('refunds an approved leave when a holiday is added and raises an HR task when one is removed', function () {
    $type = leaveTypeMake(['code' => 'HOL']);
    allocate($this->employee, $type, '10');
    $app = approveLeave(applyLeave($this->employee, $type, '2026-06-08', '2026-06-12'), $this->w->approver);
    expect(app(LeaveBalance::class)->on($this->employee, $type, '2026-06-01')['available'])->toBe('5.000');

    app(SaveHoliday::class)->handle($this->w->list, null, ['date' => '2026-06-10', 'name' => 'Cuti Peristiwa', 'kind' => 'ad_hoc']);

    expect((string) $app->fresh()->total_days)->toBe('4.000')
        ->and(app(LeaveBalance::class)->on($this->employee, $type, '2026-06-01')['available'])->toBe('6.000')
        ->and(LeaveMovement::query()->fromSource($app)->where('kind', MovementKind::Reversal->value)->count())->toBe(1)
        ->and($this->spy->marked)->toBe([$app->id, $app->id]);

    app(SaveHoliday::class)->delete(Holiday::query()->where('holiday_list_id', $this->w->list->id)->whereDate('date', '2026-06-10')->firstOrFail());

    expect((string) $app->fresh()->total_days)->toBe('4.000')
        ->and(app(LeaveBalance::class)->on($this->employee, $type, '2026-06-01')['available'])->toBe('6.000')
        ->and(LeaveReviewTask::query()->where('leave_application_id', $app->id)->where('reason', 'holiday_removed')->exists())->toBeTrue();
});

// #42
it('pro-rates on separation, may leave a negative balance and withdraws later pending leave', function () {
    $annual = leaveTypeMake(['code' => 'ANS', 'proration_method' => 'completed_months', 'rounding_step' => '1', 'prorate_on_separation' => true]);
    $policy = app(LeaveConfigActions::class)->savePolicy(null, ['name' => 'Dua Belas', 'rows' => [['leave_type_id' => $annual->id, 'annual_days' => 12]]]);
    $actions = app(PolicyAssignmentActions::class);
    $this->travelTo(CarbonImmutable::parse('2026-01-01'));
    $actions->submit($actions->saveDraft(null, ['employee_id' => $this->employee->id, 'leave_policy_id' => $policy->id, 'effective_from' => '2026-01-01']), null);
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));

    approveLeave(applyLeave($this->employee, $annual, '2026-06-08', '2026-06-17'), $this->w->approver); // 8 days
    $july = applyLeave($this->employee, $annual, '2026-07-06');

    $this->employee->update(['relieving_date' => '2026-06-30']);
    EmployeeSeparated::dispatch($this->employee->refresh());

    $allocation = LeaveAllocation::query()->where('employee_id', $this->employee->id)->where('leave_type_id', $annual->id)->firstOrFail();
    $prorate = LeaveMovement::query()->where('leave_allocation_id', $allocation->id)->where('kind', MovementKind::SeparationProrate->value)->get();

    expect($prorate)->toHaveCount(1)
        ->and((string) $prorate[0]->days)->toBe('-6.000')
        ->and(app(LeaveBalance::class)->on($this->employee, $annual, '2026-06-30')['available'])->toBe('-2.000')
        ->and($july->fresh()->status)->toBe(ApprovalStatus::Cancelled);
});

// #43
it('grants Cuti Gantian for a worked rest day and blocks revoking it once used', function () {
    $cgn = seededType('CGN');
    $this->spy->worked = ['2026-06-06' => 'present'];
    $actions = app(CompensatoryRequestActions::class);

    expect(fn () => $actions->submit($actions->saveDraft(null, $this->employee, [
        'leave_type_id' => $cgn->id, 'work_from' => '2026-06-08', 'work_to' => '2026-06-08', 'reason' => 'Bukan hari rehat',
    ]), $this->employee->user))->toThrow(ValidationException::class);

    $request = $actions->submit($actions->saveDraft(null, $this->employee, [
        'leave_type_id' => $cgn->id, 'work_from' => '2026-06-06', 'work_to' => '2026-06-06', 'reason' => 'Program Hari Terbuka',
    ]), $this->employee->user);
    app(ApprovalService::class)->approve($request->approvalRequest()->firstOrFail(), $this->w->approver);

    $lot = LeaveMovement::query()->whereIn('id', $request->fresh()->granted_movement_ids)->firstOrFail();
    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and((string) $lot->days)->toBe('1.000')
        ->and($lot->date_from->toDateString())->toBe('2026-06-07')
        ->and($lot->date_to->toDateString())->toBe('2026-09-04');

    approveLeave(applyLeave($this->employee, $cgn, '2026-06-09'), $this->w->approver);

    expect(fn () => $actions->cancel($request->fresh(), $this->w->hr, 'Tarik balik'))->toThrow(LeaveRuleViolation::class)
        ->and($request->fresh()->status)->toBe(ApprovalStatus::Approved);
});
