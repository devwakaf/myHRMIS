<?php

// spec-02 §7 cases 26–33g (lifecycle, approval hooks, ledger immutability).

use App\Domain\Core\Actions\SaveHoliday;
use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Leave\Actions\AdjustmentActions;
use App\Domain\Leave\Actions\CancelLeaveApplication;
use App\Domain\Leave\Actions\CompensatoryRequestActions;
use App\Domain\Leave\Actions\LeaveConfigActions;
use App\Domain\Leave\Actions\SubmitLeaveApplication;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LedgerIsImmutable;
use App\Domain\Leave\Models\CompensatoryLeaveRequest;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Enums\ActionType;
use App\Domain\Shared\Approval\Enums\Decision;
use App\Domain\Shared\Approval\Enums\SkipReason;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Events\ApprovalCompleted;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use App\Domain\Shared\Approval\Models\ApprovalAction;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\DocumentLocked;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\SpyAttendancePort;

beforeEach(function () {
    $this->w = leaveWorld('2026-06-01');
    $this->spy = SpyAttendancePort::install();
    $this->employee = staff();
    $this->type = leaveTypeMake(['code' => 'TST']);
    $this->service = app(ApprovalService::class);
});

function leaveAppDebits(LeaveApplication $app): Collection
{
    return LeaveMovement::query()->fromSource($app)->where('kind', MovementKind::Application->value)->get();
}

// #26
it('posts debits per lot on approval and reverses them on cancel', function () {
    $main = allocate($this->employee, $this->type, '10');
    app(AdjustmentActions::class)->submit(app(AdjustmentActions::class)->saveDraft(null, [
        'leave_allocation_id' => $main->id, 'direction' => 'add', 'days' => '2', 'effective_date' => '2026-06-01', 'expires_on' => '2026-06-30', 'reason' => 'Ganjaran',
    ]), $this->w->hr);

    $app = approveLeave(applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-10'), $this->w->approver);
    $debits = leaveAppDebits($app);

    expect($app->status)->toBe(ApprovalStatus::Approved)
        ->and($debits)->toHaveCount(2) // 2 from the adjustment lot (expires first), 1 from the main lot
        ->and($debits->sortBy('id')->pluck('days')->map(fn ($d) => (string) $d)->values()->all())->toBe(['-2.000', '-1.000'])
        ->and($this->spy->marked)->toBe([$app->id])
        ->and($app->days()->whereNotNull('leave_allocation_id')->count())->toBe(3);

    app(CancelLeaveApplication::class)->cancelApproved($app, $this->w->hr, 'Batal');

    $reversals = LeaveMovement::query()->where('kind', MovementKind::Reversal->value)->whereIn('reverses_movement_id', $debits->pluck('id'))->get();
    expect($reversals)->toHaveCount(2)
        ->and(app(LeaveBalance::class)->on($this->employee, $this->type, '2026-06-01')['available'])->toBe('12.000')
        ->and($this->spy->unmarked)->toBe([$app->id]);
});

// #27
it('never updates or deletes ledger rows and reverses a row only once', function () {
    $allocation = allocate($this->employee, $this->type, '10');
    $lot = app(LeaveLedger::class)->mainLot($allocation);

    expect(fn () => $lot->update(['days' => 5]))->toThrow(LedgerIsImmutable::class)
        ->and(fn () => $lot->delete())->toThrow(LedgerIsImmutable::class);

    app(LeaveLedger::class)->reverse($lot, CarbonImmutable::today());
    expect(fn () => app(LeaveLedger::class)->reverse($lot, CarbonImmutable::today()))->toThrow(QueryException::class);
});

// #28
it('keeps a rejected application terminal: no resubmit, no edit', function () {
    allocate($this->employee, $this->type, '10');
    $app = applyLeave($this->employee, $this->type, '2026-06-08');
    $this->service->reject($app->approvalRequest()->firstOrFail(), $this->w->approver, 'Tidak');
    $app->refresh();

    expect(fn () => app(SubmitLeaveApplication::class)->submit($app, $this->employee->user))->toThrow(DocumentLocked::class)
        ->and(fn () => app(SubmitLeaveApplication::class)->saveDraft($app, $this->employee, ['leave_type_id' => $this->type->id, 'from_date' => '2026-06-09', 'to_date' => '2026-06-09'], $this->employee->user))
        ->toThrow(DocumentLocked::class)
        ->and(fn () => $app->update(['reason' => 'x']))->toThrow(DocumentLocked::class);
});

// #29
it('lets the applicant cancel approved future leave but not once it started; HR may', function () {
    allocate($this->employee, $this->type, '10');
    $future = approveLeave(applyLeave($this->employee, $this->type, '2026-06-08'), $this->w->approver);
    $started = approveLeave(applyLeave($this->employee, $this->type, '2026-06-10'), $this->w->approver);

    actingInOrg($this->employee->user, $this->w->org)
        ->post("/leave/applications/{$future->id}/cancel", ['reason' => 'Tidak jadi'])->assertRedirect();
    expect($future->fresh()->status)->toBe(ApprovalStatus::Cancelled);

    $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00'));
    actingInOrg($this->employee->user, $this->w->org)
        ->post("/leave/applications/{$started->id}/cancel", ['reason' => 'Tidak jadi'])->assertForbidden();

    actingInOrg($this->w->hr, $this->w->org)
        ->post("/leave/applications/{$started->id}/cancel", ['reason' => 'Pembetulan HR'])->assertRedirect();
    expect($started->fresh()->status)->toBe(ApprovalStatus::Cancelled);
});

// #30
it('requires acknowledgement of block-list dates and vetoes the final approval unless exempt or overridden', function () {
    allocate($this->employee, $this->type, '10');
    $list = app(LeaveConfigActions::class)->saveBlockList(null, ['name' => 'Audit', 'dates' => [['date' => '2026-06-09', 'reason' => 'Audit tahunan']]]);

    expect(fn () => applyLeave($this->employee, $this->type, '2026-06-09'))->toThrow(ValidationException::class);

    $app = applyLeave($this->employee, $this->type, '2026-06-09', null, ['block_list_acknowledged' => true]);
    $request = $app->approvalRequest()->firstOrFail();
    expect(fn () => $this->service->approve($request, $this->w->approver))->toThrow(ApprovalCompletionBlocked::class);
    expect($app->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($request->fresh()->steps[0]->status)->toBe(StepStatus::Active)
        ->and(leaveAppDebits($app))->toHaveCount(0);

    // exempt approver
    $list->exemptUsers()->sync([$this->w->approver->id]);
    $this->service->approve($request, $this->w->approver);
    expect($app->fresh()->status)->toBe(ApprovalStatus::Approved);
});

it('lets an HR override with a reason pass the block-list veto', function () {
    allocate($this->employee, $this->type, '10');
    app(LeaveConfigActions::class)->saveBlockList(null, ['name' => 'Audit', 'dates' => [['date' => '2026-06-09', 'reason' => 'Audit']]]);
    $app = applyLeave($this->employee, $this->type, '2026-06-09', null, ['block_list_acknowledged' => true]);

    $this->service->override($app->approvalRequest()->firstOrFail(), $this->w->hr, Decision::Approve, 'Keperluan mendesak');

    expect($app->fresh()->status)->toBe(ApprovalStatus::Approved)->and(leaveAppDebits($app))->toHaveCount(1);
});

// #31
it('allows AM + PM halves on one date but refuses duplicate sessions and full-over-half', function () {
    allocate($this->employee, $this->type, '10');
    applyLeave($this->employee, $this->type, '2026-06-09', null, ['half_day_date' => '2026-06-09', 'half_day_session' => 'am']);
    $pm = applyLeave($this->employee, $this->type, '2026-06-09', null, ['half_day_date' => '2026-06-09', 'half_day_session' => 'pm']);

    expect($pm->status)->toBe(ApprovalStatus::Pending)
        ->and(fn () => applyLeave($this->employee, $this->type, '2026-06-09', null, ['half_day_date' => '2026-06-09', 'half_day_session' => 'am']))->toThrow(ValidationException::class)
        ->and(fn () => applyLeave($this->employee, $this->type, '2026-06-09'))->toThrow(ValidationException::class);
});

// #32
it('chains consecutive same-type leave across a weekend for max_consecutive_days', function () {
    $type = leaveTypeMake(['code' => 'CON', 'max_consecutive_days' => '10']);
    allocate($this->employee, $type, '20');
    approveLeave(applyLeave($this->employee, $type, '2026-06-08', '2026-06-12'), $this->w->approver);
    applyLeave($this->employee, $type, '2026-06-15', '2026-06-19');

    expect(fn () => applyLeave($this->employee, $type, '2026-06-22'))->toThrow(ValidationException::class);
});

// #33, #33a, #33b
describe('three-step workflow', function () {
    beforeEach(function () {
        $this->a = userIn($this->w->org, 'kakitangan', ['name' => 'Penyelia']);
        $this->b = userIn($this->w->org, 'kakitangan', ['name' => 'Ketua Jabatan']);
        leaveWorkflow([
            leaveStep('Penyelia', $this->a),
            leaveStep('Ketua Jabatan', $this->b),
            ['name' => 'HR', 'rule_type' => 'role', 'rule_config' => ['role' => 'pengurus_hr'], 'mode' => 'any_one', 'skip_if_applicant' => true,
                'skip_if_same_as_previous' => false, 'exclude_submitter' => true, 'on_no_approver' => 'fail',
                'condition' => [['attribute' => 'leave.days', 'operator' => '>=', 'value' => 5]]],
        ]);
    });

    it('stays pending through the steps and posts once on the final approval', function () {
        Event::fake([ApprovalCompleted::class]);
        $ctg = seededType('CTG');
        $app = applyLeave($this->employee, $ctg, '2026-06-08', '2026-06-15', ['reason' => 'Urusan keluarga']);
        $request = $app->approvalRequest()->firstOrFail();

        $this->service->approve($request, $this->a);
        $this->service->approve($request, $this->b);
        expect($app->fresh()->status)->toBe(ApprovalStatus::Pending)
            ->and(leaveAppDebits($app))->toHaveCount(0)
            ->and(app(LeaveBalance::class)->on($this->employee, $ctg, '2026-06-08')['reserved'])->toBe('6.000');

        $this->service->approve($request, $this->w->hr);
        $debits = leaveAppDebits($app);
        expect($app->fresh()->status)->toBe(ApprovalStatus::Approved)
            ->and($debits)->toHaveCount(1)
            ->and((string) $debits[0]->unpaid_days)->toBe('6.000')
            ->and($this->spy->marked)->toBe([$app->id]);
        Event::assertDispatched(ApprovalCompleted::class, 1);
    });

    it('ends as rejected at step 2 with no ledger rows and no resubmit', function () {
        $app = applyLeave($this->employee, seededType('CTG'), '2026-06-08', '2026-06-15', ['reason' => 'x']);
        $request = $app->approvalRequest()->firstOrFail();
        $this->service->approve($request, $this->a);
        $this->service->reject($request, $this->b, 'Kakitangan tidak mencukupi');

        expect($app->fresh()->status)->toBe(ApprovalStatus::Rejected)
            ->and(leaveAppDebits($app))->toHaveCount(0)
            ->and(fn () => app(SubmitLeaveApplication::class)->submit($app->fresh(), $this->employee->user))->toThrow(DocumentLocked::class);
    });

    it('skips the HR step for a 3-day leave', function () {
        allocate($this->employee, $this->type, '10');
        $app = applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-10');
        $request = $app->approvalRequest()->firstOrFail();

        expect($request->steps[2]->status)->toBe(StepStatus::Skipped)
            ->and($request->steps[2]->skip_reason)->toBe(SkipReason::ConditionFalse);

        $this->service->approve($request, $this->a);
        $this->service->approve($request, $this->b);
        expect($app->fresh()->status)->toBe(ApprovalStatus::Approved);
    });
});

// #33c
it('vetoes the final approval when the balance was consumed meanwhile', function () {
    $allocation = allocate($this->employee, $this->type, '10');
    $app = applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-15'); // 6 days
    $adjust = app(AdjustmentActions::class);
    $adjust->submit($adjust->saveDraft(null, ['leave_allocation_id' => $allocation->id, 'direction' => 'reduce', 'days' => '6', 'effective_date' => '2026-06-01', 'reason' => 'Pembetulan']), $this->w->hr);

    try {
        $this->service->approve($app->approvalRequest()->firstOrFail(), $this->w->approver);
        $this->fail('expected a veto');
    } catch (ApprovalCompletionBlocked $e) {
        expect($e->check->translationKey)->toBe('leave.approval.insufficient_balance');
    }
    expect($app->fresh()->status)->toBe(ApprovalStatus::Pending)->and(leaveAppDebits($app))->toHaveCount(0);
});

// #33d
it('rolls everything back when the attendance port fails inside onApprovalCompleted', function () {
    allocate($this->employee, $this->type, '10');
    $app = applyLeave($this->employee, $this->type, '2026-06-08');
    $this->spy->failOnMark = true;

    expect(fn () => $this->service->approve($app->approvalRequest()->firstOrFail(), $this->w->approver))->toThrow(RuntimeException::class);
    expect($app->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(leaveAppDebits($app))->toHaveCount(0)
        ->and($app->approvalRequest()->first()->steps[0]->status)->toBe(StepStatus::Active);
});

// #33e
it('writes nothing on withdraw, one reversal per debit on cancel, and records the system actor on separation', function () {
    allocate($this->employee, $this->type, '10');
    $pending = applyLeave($this->employee, $this->type, '2026-06-08');
    app(CancelLeaveApplication::class)->withdraw($pending, $this->employee->user, 'Tarik balik');
    expect(LeaveMovement::query()->fromSource($pending)->count())->toBe(0);

    $approved = approveLeave(applyLeave($this->employee, $this->type, '2026-06-22'), $this->w->approver);
    $july = applyLeave($this->employee, $this->type, '2026-07-06');

    $this->employee->update(['relieving_date' => '2026-06-30']);
    EmployeeSeparated::dispatch($this->employee->refresh());

    $action = ApprovalAction::query()->where('approval_request_id', $july->approvalRequest()->first()->id)->where('action', ActionType::Cancelled->value)->first();
    expect($july->fresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and($action->actor_user_id)->toBeNull()
        ->and($approved->fresh()->status)->toBe(ApprovalStatus::Approved);
});

// #33f
it('recalculates day counts on a holiday change without touching frozen approval attributes', function () {
    allocate($this->employee, $this->type, '10');
    $app = applyLeave($this->employee, $this->type, '2026-06-08', '2026-06-15'); // 6 days

    app(SaveHoliday::class)->handle($this->w->list, null, ['date' => '2026-06-09', 'name' => 'Cuti Peristiwa', 'kind' => 'ad_hoc']);

    expect((string) $app->fresh()->total_days)->toBe('5.000')
        ->and($app->approvalRequest()->first()->frozen_attributes['leave.days'])->toBe('6.000')
        ->and($app->fresh()->days()->whereDate('date', '2026-06-09')->value('portion'))->toBe('0.000');
});

// #33g
it('registers compensatory_leave separately from leave', function () {
    $registry = app(ApprovalTypeRegistry::class);

    expect($registry->get('leave')->modelClass)->toBe(LeaveApplication::class)
        ->and($registry->get('compensatory_leave')->modelClass)->toBe(CompensatoryLeaveRequest::class);

    $other = userIn($this->w->org, 'kakitangan', ['name' => 'Pelulus Gantian']);
    leaveWorkflow([leaveStep('Gantian', $other)], 'compensatory_leave');
    $this->spy->worked = ['2026-06-06' => 'present'];
    $actions = app(CompensatoryRequestActions::class);
    $request = $actions->submit($actions->saveDraft(null, $this->employee, [
        'leave_type_id' => seededType('CGN')->id, 'work_from' => '2026-06-06', 'work_to' => '2026-06-06', 'reason' => 'Program',
    ]), $this->employee->user);

    $assignees = $request->approvalRequest()->first()->steps[0]->assignees->pluck('user_id')->all();
    expect($assignees)->toBe([$other->id]);
});
