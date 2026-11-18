<?php

use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DepartmentApprover;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeApprover;
use App\Domain\Shared\Approval\Actions\PublishApprovalWorkflow;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Enums\Decision;
use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Events\ApprovalCancelled;
use App\Domain\Shared\Approval\Events\ApprovalCompleted;
use App\Domain\Shared\Approval\Events\ApprovalStepActivated;
use App\Domain\Shared\Approval\Exceptions\AlreadyDecided;
use App\Domain\Shared\Approval\Exceptions\ApprovalCompletionBlocked;
use App\Domain\Shared\Approval\Exceptions\NoApproverFound;
use App\Domain\Shared\Approval\Exceptions\RequestNotPending;
use App\Domain\Shared\Approval\Exceptions\SelfApprovalForbidden;
use App\Domain\Shared\Approval\Jobs\SendApprovalReminders;
use App\Domain\Shared\Approval\Jobs\SyncApproverRoles;
use App\Domain\Shared\Approval\Models\ApprovalAction;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Approval\Models\ApprovalWorkflow;
use App\Domain\Shared\Approval\Notifications\ApprovalCancelledNotice;
use App\Domain\Shared\Approval\Notifications\ApprovalReminder;
use App\Domain\Shared\Approval\Notifications\ApprovalRequested;
use App\Domain\Shared\Audit\Activity;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\DocumentLocked;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeApprovable;

beforeEach(function () {
    $this->org = inOrg(makeOrganisation());
    FakeApprovable::register();
    $this->service = app(ApprovalService::class);

    $this->applicantUser = userIn($this->org, 'kakitangan');
    $this->applicant = employee([], $this->applicantUser);
    $this->a = userIn($this->org, 'kakitangan', ['name' => 'Pelulus A']);
    $this->b = userIn($this->org, 'kakitangan', ['name' => 'Pelulus B']);
    $this->c = userIn($this->org, 'kakitangan', ['name' => 'Pelulus C']);
    $this->hr = userIn($this->org, 'pengurus_hr', ['name' => 'Pengurus HR']);
});

function step(string $name, string $rule, array $config = [], array $extra = []): array
{
    return array_merge([
        'name' => $name, 'rule_type' => $rule, 'rule_config' => $config, 'mode' => 'any_one',
        'skip_if_applicant' => true, 'skip_if_same_as_previous' => false, 'exclude_submitter' => true,
        'on_no_approver' => 'fail', 'condition' => null,
    ], $extra);
}

function workflow(array $steps, array $options = []): ApprovalWorkflow
{
    return app(PublishApprovalWorkflow::class)->handle(OrganisationContext::id(), 'test', array_merge(['name' => 'Ujian', 'steps' => $steps], $options));
}

function doc(Employee $applicant, string $days = '2'): FakeApprovable
{
    return FakeApprovable::query()->create(['employee_id' => $applicant->id, 'days' => $days]);
}

// B8 #43
it('moves a 3-step workflow to approved and calls the completion hook once inside the transaction', function () {
    Event::fake([ApprovalCompleted::class, ApprovalStepActivated::class]);
    workflow([
        step('Satu', 'specific_user', ['user_id' => $this->a->id]),
        step('Dua', 'specific_user', ['user_id' => $this->b->id]),
        step('Tiga', 'specific_user', ['user_id' => $this->c->id]),
    ]);
    $doc = doc($this->applicant);

    $request = $this->service->submit($doc, $this->applicantUser);
    expect($request->current_sequence)->toBe(1)->and($doc->fresh()->status)->toBe(ApprovalStatus::Pending);

    $this->service->approve($request, $this->a);
    expect($request->fresh()->current_sequence)->toBe(2);
    $this->service->approve($request, $this->b);
    Event::assertNotDispatched(ApprovalCompleted::class);
    $this->service->approve($request, $this->c, 'OK');

    expect($request->fresh()->status)->toBe(RequestStatus::Approved)
        ->and($doc->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and(FakeApprovable::$calls['completed'])->toBe(1)
        ->and(FakeApprovable::$calls['check'])->toBe(1)
        ->and(FakeApprovable::$log[0])->toBe(['completed', true]);
    Event::assertDispatched(ApprovalCompleted::class, 1);
    Event::assertDispatched(ApprovalStepActivated::class, 3);
});

// B8 #44
it('rejects at step 2, cancels step 3 and refuses further approvals', function () {
    Notification::fake();
    workflow([
        step('Satu', 'specific_user', ['user_id' => $this->a->id]),
        step('Dua', 'specific_user', ['user_id' => $this->b->id]),
        step('Tiga', 'specific_user', ['user_id' => $this->c->id]),
    ]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);
    $this->service->approve($request, $this->a);

    expect(fn () => $this->service->reject($request, $this->b, ''))->toThrow(ValidationException::class);
    $this->service->reject($request, $this->b, 'Tidak sesuai');

    $steps = $request->fresh()->steps;
    expect($request->fresh()->status)->toBe(RequestStatus::Rejected)
        ->and($steps[1]->status)->toBe(StepStatus::Rejected)
        ->and($steps[2]->status)->toBe(StepStatus::Cancelled)
        ->and($steps[2]->assignees)->toHaveCount(0)
        ->and(FakeApprovable::$calls['rejected'])->toBe(1)
        ->and(fn () => $this->service->approve($request, $this->a))->toThrow(RequestNotPending::class)
        ->and(fn () => $this->service->approve($request, $this->c))->toThrow(RequestNotPending::class);
    Notification::assertNotSentTo($this->c, ApprovalRequested::class);
});

// B8 #45
it('completes an "all" step only when every assignee approved and marks others not required in "any_one"', function () {
    $dept = Department::query()->findOrFail($this->applicant->department_id);
    DepartmentApprover::query()->create(['department_id' => $dept->id, 'approval_type' => 'test', 'user_id' => $this->a->id, 'sort_order' => 0]);
    DepartmentApprover::query()->create(['department_id' => $dept->id, 'approval_type' => 'test', 'user_id' => $this->b->id, 'sort_order' => 1]);

    workflow([
        step('Semua', 'department_approvers', ['selection' => 'all'], ['mode' => 'all']),
        step('Mana-mana', 'department_approvers', ['selection' => 'all'], ['mode' => 'any_one']),
    ]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);

    $this->service->approve($request, $this->a);
    expect($request->fresh()->current_sequence)->toBe(1);
    $this->service->approve($request, $this->b);
    expect($request->fresh()->current_sequence)->toBe(2);

    $this->service->approve($request, $this->b);
    $step2 = $request->fresh()->steps[1];
    expect($request->fresh()->status)->toBe(RequestStatus::Approved)
        ->and($step2->assignees->firstWhere('user_id', $this->a->id)->decision)->toBe(AssigneeDecision::NotRequired);
});

// B8 #46
it('skips a step whose condition is false and validates conditions against the catalogue', function () {
    workflow([
        step('Satu', 'specific_user', ['user_id' => $this->a->id]),
        step('Panjang', 'specific_user', ['user_id' => $this->b->id], ['condition' => [['attribute' => 'test.days', 'operator' => '>', 'value' => 3]]]),
    ]);

    $short = $this->service->submit(doc($this->applicant, '2'), $this->applicantUser);
    $long = $this->service->submit(doc($this->applicant, '5'), $this->applicantUser);

    expect($short->steps[1]->status)->toBe(StepStatus::Skipped)
        ->and($short->steps[1]->skip_reason->value)->toBe('condition_false')
        ->and($long->steps[1]->status)->toBe(StepStatus::Waiting)
        ->and(fn () => workflow([step('X', 'specific_user', ['user_id' => $this->a->id], ['condition' => [['attribute' => 'leave.days', 'operator' => '>', 'value' => 1]]])]))
        ->toThrow(ValidationException::class);
});

// B8 #47
it('auto-skips the head-of-department step when the supervisor (also head) already approved', function () {
    $boss = employee([], $this->a);
    $this->applicant->update(['reports_to_id' => $boss->id]);
    Department::query()->whereKey($this->applicant->department_id)->update(['head_employee_id' => $boss->id]);

    workflow([
        step('Penyelia', 'reports_to', ['level' => 1]),
        step('Ketua Jabatan', 'department_head', ['walk_up' => true], ['skip_if_same_as_previous' => true]),
    ]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);
    $this->service->approve($request, $this->a);

    $steps = $request->fresh()->steps;
    expect($request->fresh()->status)->toBe(RequestStatus::Approved)
        ->and($steps[1]->status)->toBe(StepStatus::Skipped)
        ->and($steps[1]->skip_reason->value)->toBe('same_as_previous');
});

// B8 #48
it('walks up to the parent head when the applicant heads the department', function () {
    $dept = Department::query()->findOrFail($this->applicant->department_id);
    $parentHead = employee([], $this->b);
    Department::query()->whereKey($dept->parent_id)->update(['head_employee_id' => $parentHead->id]);
    $dept->update(['head_employee_id' => $this->applicant->id]);

    workflow([step('Ketua', 'department_head', ['walk_up' => true])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);
    expect($request->steps[0]->assignees->pluck('user_id')->all())->toBe([$this->b->id]);

    workflow([step('Ketua', 'department_head', ['walk_up' => false]), step('HR', 'specific_user', ['user_id' => $this->c->id])]);
    $second = $this->service->submit(doc($this->applicant), $this->applicantUser);
    expect($second->steps[0]->status)->toBe(StepStatus::Skipped)
        ->and($second->steps[0]->skip_reason->value)->toBe('applicant_only');
});

// B8 #49
it('builds the applicant-chooses candidate list and rejects picks outside it', function () {
    $dept = Department::query()->findOrFail($this->applicant->department_id);
    $root = Department::query()->findOrFail($dept->parent_id);
    $inactive = userIn($this->org, 'kakitangan', ['is_active' => false]);
    EmployeeApprover::query()->create(['employee_id' => $this->applicant->id, 'approval_type' => 'test', 'user_id' => $this->a->id]);
    DepartmentApprover::query()->create(['department_id' => $dept->id, 'approval_type' => 'test', 'user_id' => $this->b->id, 'sort_order' => 0]);
    DepartmentApprover::query()->create(['department_id' => $dept->id, 'approval_type' => 'test', 'user_id' => $this->a->id, 'sort_order' => 1]);
    DepartmentApprover::query()->create(['department_id' => $root->id, 'approval_type' => 'test', 'user_id' => $inactive->id, 'sort_order' => 0]);
    DepartmentApprover::query()->create(['department_id' => $root->id, 'approval_type' => 'test', 'user_id' => $this->c->id, 'sort_order' => 1]);

    $wf = workflow([step('Pilih', 'named_or_department', ['selection' => 'applicant_chooses', 'walk_ancestors' => true])]);
    $stepId = $wf->steps[0]->id;
    $route = $this->service->preview(doc($this->applicant));

    expect(array_column($route->steps[0]['candidates'], 'id'))->toBe([$this->a->id, $this->b->id, $this->c->id])
        ->and($route->steps[0]['default_choice'])->toBe($this->a->id);

    $request = $this->service->submit(doc($this->applicant), $this->applicantUser, [(string) $stepId => $this->c->id]);
    expect($request->steps[0]->assignees->pluck('user_id')->all())->toBe([$this->c->id]);

    $outsider = userIn($this->org, 'kakitangan');
    expect(fn () => $this->service->submit(doc($this->applicant), $this->applicantUser, [(string) $stepId => $outsider->id]))
        ->toThrow(ValidationException::class);

    EmployeeApprover::query()->delete();
    $route = $this->service->preview(doc($this->applicant));
    expect($route->steps[0]['default_choice'])->toBe($this->b->id);
});

// B8 #50
it('resolves reports_to level 2 and applies on_no_approver for a missing chain', function () {
    $boss = employee([], $this->a);
    $bigBoss = employee([], $this->b);
    $boss->update(['reports_to_id' => $bigBoss->id]);
    $this->applicant->update(['reports_to_id' => $boss->id]);

    workflow([step('Atas', 'reports_to', ['level' => 2])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);
    expect($request->steps[0]->assignees->pluck('user_id')->all())->toBe([$this->b->id]);

    $bigBoss->update(['user_id' => null]);
    expect(fn () => $this->service->submit(doc($this->applicant), $this->applicantUser))->toThrow(NoApproverFound::class);
});

// B8 #51
it('assigns all active holders of a role in this organisation only', function () {
    $hr2 = userIn($this->org, 'pengurus_hr');
    $other = makeOrganisation();
    $outsideHr = userIn($other, 'pengurus_hr');
    inOrg($this->org);

    workflow([step('HR', 'role', ['role' => 'pengurus_hr'])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);

    expect($request->steps[0]->assignees->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->hr->id, $hr2->id])->sort()->values()->all())
        ->and($request->steps[0]->assignees->pluck('user_id'))->not->toContain($outsideHr->id);
});

// B8 #52
it('applies on_no_approver fail, skip and hr_queue', function () {
    $nobody = ['level' => 1];
    workflow([step('Penyelia', 'reports_to', $nobody, ['on_no_approver' => 'fail'])]);
    $countBefore = ApprovalRequest::query()->count();
    expect(fn () => $this->service->submit(doc($this->applicant), $this->applicantUser))
        ->toThrow(NoApproverFound::class);
    expect(ApprovalRequest::query()->count())->toBe($countBefore);

    workflow([step('Penyelia', 'reports_to', $nobody, ['on_no_approver' => 'skip']), step('Akhir', 'specific_user', ['user_id' => $this->a->id])]);
    $skipped = $this->service->submit(doc($this->applicant), $this->applicantUser);
    expect($skipped->steps[0]->status)->toBe(StepStatus::Skipped)->and($skipped->steps[0]->skip_reason->value)->toBe('no_approver');

    workflow([step('Penyelia', 'reports_to', $nobody, ['on_no_approver' => 'hr_queue'])]);
    $queued = $this->service->submit(doc($this->applicant), $this->applicantUser);
    expect($queued->steps[0]->status)->toBe(StepStatus::Active)->and($queued->steps[0]->assignees)->toHaveCount(0);

    actingInOrg($this->hr, $this->org)->get('/approvals?tab=no_approver')
        ->assertInertia(fn ($page) => $page->where('requests.data.0.id', $queued->id));
});

// B8 #53
it('never assigns the applicant and blocks self-approval even for an HR override', function () {
    $hrEmployee = employee([], $this->hr);
    workflow([step('HR', 'role', ['role' => 'pengurus_hr'], ['skip_if_applicant' => false]), step('A', 'specific_user', ['user_id' => $this->a->id])]);
    $other = userIn($this->org, 'pengurus_hr');

    $request = $this->service->submit(doc($hrEmployee), $this->hr);
    expect($request->steps[0]->assignees->pluck('user_id')->all())->toBe([$other->id])
        ->and(fn () => $this->service->override($request, $this->hr, Decision::Approve, 'Sendiri'))->toThrow(SelfApprovalForbidden::class);
});

it('stops the submitter (HR on behalf) from approving when exclude_submitter is on', function () {
    workflow([step('HR', 'role', ['role' => 'pengurus_hr'])]);
    $other = userIn($this->org, 'pengurus_hr');

    $request = $this->service->submit(doc($this->applicant), $this->hr);
    expect($request->steps[0]->assignees->pluck('user_id')->all())->toBe([$other->id])
        ->and(fn () => $this->service->override($request, $this->hr, Decision::Approve, 'Saya hantar'))->toThrow(SelfApprovalForbidden::class);
});

// B8 #54
it('forbids non-assignees, logs HR overrides and hides waiting steps from their assignees', function () {
    workflow([
        step('Satu', 'specific_user', ['user_id' => $this->a->id]),
        step('Dua', 'specific_user', ['user_id' => $this->b->id]),
    ]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);

    actingInOrg($this->c, $this->org)->post("/approvals/{$request->id}/approve")->assertForbidden();
    expect($this->service->canView($this->b, $request))->toBeFalse()
        ->and($this->service->canView($this->a, $request))->toBeTrue();

    actingInOrg($this->hr, $this->org)->post("/approvals/{$request->id}/override", ['decision' => 'approve', 'reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/approvals/{$request->id}/override", ['decision' => 'approve', 'reason' => 'Pelulus bercuti'])->assertSessionHasNoErrors();

    $request->refresh();
    expect($request->current_sequence)->toBe(2)
        ->and($request->status)->toBe(RequestStatus::Pending)
        ->and(ApprovalAction::query()->where('approval_request_id', $request->id)->where('action', 'override_approved')->value('note'))->toBe('Pelulus bercuti')
        ->and($this->service->canView($this->b, $request))->toBeTrue();
});

// B8 #55
it('lets a supervisor assignee see the request but not the applicant profile', function () {
    $boss = employee([], $this->a);
    $this->applicant->update(['reports_to_id' => $boss->id]);
    workflow([step('Penyelia', 'reports_to', ['level' => 1])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);

    actingInOrg($this->a, $this->org)->get("/approvals?request={$request->id}")
        ->assertInertia(fn ($page) => $page->where('selected.id', $request->id)->where('selected.decision_context.days', '2.000'));
    $this->get("/employees/{$this->applicant->id}")->assertForbidden();
});

// B8 #56
it('versions a used workflow; in-flight requests keep their version', function () {
    $v1 = workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id])]);
    $inFlight = $this->service->submit(doc($this->applicant), $this->applicantUser);

    $v2 = workflow([step('Baharu', 'specific_user', ['user_id' => $this->b->id])]);
    expect($v2->version)->toBe(2)->and($v1->fresh()->is_active)->toBeFalse();

    $new = $this->service->submit(doc($this->applicant), $this->applicantUser);
    expect($new->approval_workflow_id)->toBe($v2->id);

    $this->service->approve($inFlight, $this->a);
    expect($inFlight->fresh()->status)->toBe(RequestStatus::Approved)
        ->and($inFlight->fresh()->approval_workflow_id)->toBe($v1->id);
});

it('edits an unused workflow in place', function () {
    $v1 = workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id])]);
    $again = workflow([step('Dua', 'specific_user', ['user_id' => $this->b->id])]);

    expect($again->id)->toBe($v1->id)->and($again->version)->toBe(1)->and($again->steps[0]->name)->toBe('Dua');
});

// B8 #57
it('records exactly one decision when two any-one assignees approve', function () {
    $dept = Department::query()->findOrFail($this->applicant->department_id);
    DepartmentApprover::query()->create(['department_id' => $dept->id, 'approval_type' => 'test', 'user_id' => $this->a->id, 'sort_order' => 0]);
    DepartmentApprover::query()->create(['department_id' => $dept->id, 'approval_type' => 'test', 'user_id' => $this->b->id, 'sort_order' => 1]);
    workflow([step('Jabatan', 'department_approvers', ['selection' => 'all']), step('Akhir', 'specific_user', ['user_id' => $this->c->id])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);

    $this->service->approve($request, $this->a);
    expect(fn () => $this->service->approve($request, $this->b))->toThrow(AlreadyDecided::class)
        ->and(ApprovalAction::query()->where('approval_request_id', $request->id)->where('action', 'approved')->count())->toBe(1);
});

// B8 #58, #61b
it('withdraws a pending request, cancels an approved one and dispatches ApprovalCancelled after commit', function () {
    Notification::fake();
    Event::fake([ApprovalCancelled::class]);
    workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id]), step('Dua', 'specific_user', ['user_id' => $this->b->id])]);

    $pending = $this->service->submit(doc($this->applicant), $this->applicantUser);
    $this->service->cancel($pending, $this->applicantUser, 'Tidak perlu lagi');
    $steps = $pending->fresh()->steps;
    expect($pending->fresh()->status)->toBe(RequestStatus::Cancelled)
        ->and($steps->pluck('status')->all())->toBe([StepStatus::Cancelled, StepStatus::Cancelled])
        ->and(FakeApprovable::$log[0])->toBe(['cancelled', false]);

    $approved = $this->service->submit(doc($this->applicant), $this->applicantUser);
    $this->service->approve($approved, $this->a);
    $this->service->approve($approved, $this->b);
    $this->service->cancel($approved, $this->hr, 'Dibatalkan HR');
    expect(end(FakeApprovable::$log))->toBe(['cancelled', true]);

    $system = $this->service->submit(doc($this->applicant), $this->applicantUser);
    $this->service->cancel($system, null, 'Pemberhentian');

    Event::assertDispatched(ApprovalCancelled::class, fn ($e) => $e->request->id === $pending->id && $e->wasApproved === false && $e->notifyUserIds === [$this->a->id]);
    Event::assertDispatched(ApprovalCancelled::class, fn ($e) => $e->request->id === $approved->id && $e->wasApproved === true);
    Event::assertDispatched(ApprovalCancelled::class, fn ($e) => $e->request->id === $system->id && $e->actorId === null);
});

it('notifies active assignees when a request is withdrawn', function () {
    Notification::fake();
    workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);
    $this->service->cancel($request, $this->applicantUser, 'Tarik balik');

    Notification::assertSentTo($this->a, ApprovalCancelledNotice::class);
});

// B8 #59
it('sends one reminder per day per step after remind_after_days', function () {
    Notification::fake();
    workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id], ['remind_after_days' => 2])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);
    $request->steps[0]->update(['activated_at' => now()->subDays(3)]);

    (new SendApprovalReminders)->handle();
    (new SendApprovalReminders)->handle();
    Notification::assertSentToTimes($this->a, ApprovalReminder::class, 1);

    $this->travel(1)->days();
    (new SendApprovalReminders)->handle();
    Notification::assertSentToTimes($this->a, ApprovalReminder::class, 2);
});

// B8 #60
it('grants the pelulus role on assignment, revokes it when unreferenced and queues notifications in the recipient locale', function () {
    Notification::fake();
    $this->a->update(['locale' => 'en']);
    $roles = app(RoleService::class);
    workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);

    expect($roles->hasRole($this->a, $this->org->id, 'pelulus'))->toBeTrue();
    Notification::assertSentTo($this->a, ApprovalRequested::class, function ($notification, $channels, $notifiable, $locale) {
        return $locale === 'en' && $notification instanceof ShouldQueue;
    });

    $this->service->approve($request, $this->a);
    workflow([step('Lain', 'specific_user', ['user_id' => $this->b->id])]);
    (new SyncApproverRoles)->handle($roles, app(OrganisationMembership::class), $this->service);

    expect($roles->hasRole($this->a, $this->org->id, 'pelulus'))->toBeFalse()
        ->and($roles->hasRole($this->b, $this->org->id, 'pelulus'))->toBeTrue();
});

// B8 #61a
it('vetoes completion without persisting anything', function () {
    Event::fake([ApprovalCompleted::class]);
    workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id]), step('Dua', 'specific_user', ['user_id' => $this->b->id])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);
    FakeApprovable::$completionCheck = ApprovalCheck::fail('approval.errors.not_pending');

    $this->service->approve($request, $this->a);
    expect(FakeApprovable::$calls['check'])->toBe(0);

    $actions = ApprovalAction::query()->where('approval_request_id', $request->id)->count();
    expect(fn () => $this->service->approve($request, $this->b))->toThrow(ApprovalCompletionBlocked::class);

    $step = $request->fresh()->steps[1];
    expect($step->status)->toBe(StepStatus::Active)
        ->and($step->assignees[0]->decision)->toBe(AssigneeDecision::Pending)
        ->and(ApprovalAction::query()->where('approval_request_id', $request->id)->count())->toBe($actions)
        ->and(FakeApprovable::$calls['completed'])->toBe(0);
    Event::assertNotDispatched(ApprovalCompleted::class);

    FakeApprovable::$completionCheck = null;
    FakeApprovable::$throwOnCompleted = true;
    expect(fn () => $this->service->approve($request, $this->b))->toThrow(RuntimeException::class);
    expect($request->fresh()->status)->toBe(RequestStatus::Pending)
        ->and($request->fresh()->steps[1]->assignees[0]->decision)->toBe(AssigneeDecision::Pending);
});

// B8 #61c
it('evaluates conditions on the attributes frozen at submit', function () {
    workflow([
        step('Satu', 'specific_user', ['user_id' => $this->a->id]),
        step('Panjang', 'specific_user', ['user_id' => $this->b->id], ['condition' => [['attribute' => 'test.days', 'operator' => '>', 'value' => 3]]]),
    ]);
    $doc = doc($this->applicant, '2');
    $request = $this->service->submit($doc, $this->applicantUser);

    FakeApprovable::withLockBypass('holiday_list_changed', fn () => $doc->update(['days' => '9']));
    $this->service->approve($request, $this->a);

    expect($request->fresh()->status)->toBe(RequestStatus::Approved)
        ->and($request->fresh()->frozen_attributes['test.days'])->toBe('2');
});

// B8 #61d
it('allows only the documented lock bypass on submitted documents and logs it', function () {
    workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id])]);
    $doc = doc($this->applicant, '3');
    $this->service->submit($doc, $this->applicantUser);
    $doc->refresh();

    expect(fn () => $doc->update(['days' => '2']))->toThrow(DocumentLocked::class);
    $doc->refresh();
    expect(fn () => FakeApprovable::withLockBypass('holiday_list_changed', fn () => $doc->update(['note' => 'x'])))->toThrow(DocumentLocked::class);
    $doc->refresh();

    FakeApprovable::withLockBypass('holiday_list_changed', fn () => $doc->update(['days' => '2']));
    expect($doc->fresh()->days)->toBe('2.000');

    $log = Activity::query()->where('event', 'lock_bypass')->latest('id')->first();
    expect($log->properties['bypass'])->toBe(['holiday_list_changed'])
        ->and($log->properties['changes']['days']['old'])->toBe('3.000');
});

it('serves the inbox, workflow settings and preview screens', function () {
    workflow([step('Satu', 'specific_user', ['user_id' => $this->a->id])]);
    $request = $this->service->submit(doc($this->applicant), $this->applicantUser);

    actingInOrg($this->a, $this->org)->get('/approvals')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Approvals/Index')->where('requests.data.0.id', $request->id)->where('requests.data.0.step.name', 'Satu'));
    $this->post('/approvals/bulk-approve', ['ids' => [$request->id]])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(RequestStatus::Approved);

    actingInOrg($this->hr, $this->org)->get('/settings/approval-workflows')->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/ApprovalWorkflows/Index')->has('cards', 6));
    $this->get('/settings/approval-workflows/leave')->assertOk();
    $this->postJson('/settings/approval-workflows/leave/preview', ['employee_id' => $this->applicant->id, 'attributes' => ['leave.days' => 2]])
        ->assertOk()->assertJsonPath('steps.0.outcome', 'no_approver_fail');
    $this->put('/settings/approval-workflows/leave', [
        'name' => 'Cuti 3 Langkah',
        'steps' => [
            step('Penyelia', 'reports_to', ['level' => 1], ['on_no_approver' => 'skip']),
            step('Ketua Jabatan', 'department_head', ['walk_up' => true], ['skip_if_same_as_previous' => true]),
            step('HR', 'role', ['role' => 'pengurus_hr'], ['condition' => [['attribute' => 'leave.days', 'operator' => '>', 'value' => 3]]]),
        ],
    ])->assertSessionHasNoErrors();
    expect(ApprovalWorkflow::query()->activeFor('leave')->first()->steps)->toHaveCount(3);

    $kkt = userIn($this->org, 'kakitangan');
    actingInOrg($kkt, $this->org)->put('/settings/approval-workflows/leave', ['name' => 'x', 'steps' => []])->assertForbidden();
});
