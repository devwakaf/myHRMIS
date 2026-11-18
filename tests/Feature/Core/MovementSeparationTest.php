<?php

use App\Domain\Core\Actions\MovementActions;
use App\Domain\Core\Actions\ProcessSeparation;
use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Core\Jobs\ApplyDueEmployeeMovements;
use App\Domain\Core\Jobs\ProcessDueSeparations;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeMovement;
use App\Domain\Core\Models\EmployeeWorkHistory;
use App\Domain\Core\Models\Grade;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(10, 0));
    $this->org = inOrg(makeOrganisation());
    $this->hr = userIn($this->org, 'pengurus_hr');
    $this->actions = app(MovementActions::class);
    $this->employee = employee(['date_of_joining' => '2020-01-06']);
    $this->newDept = Department::factory()->create();
});

function movement(array $data): EmployeeMovement
{
    return app(MovementActions::class)->saveDraft(null, $data + ['employee_id' => test()->employee->id])['movement'];
}

// B8 #27
it('applies a movement effective today immediately and rolls the work history', function () {
    $m = movement(['type' => 'transfer', 'effective_date' => '2026-06-15', 'new_department_id' => $this->newDept->id]);
    $this->actions->submit($m, $this->hr);

    $history = EmployeeWorkHistory::query()->where('employee_id', $this->employee->id)->orderBy('from_date')->get();
    expect($this->employee->fresh()->department_id)->toBe($this->newDept->id)
        ->and($m->fresh()->isApplied())->toBeTrue()
        ->and($m->fresh()->before_values['department_id'])->not->toBe($this->newDept->id)
        ->and($history)->toHaveCount(2)
        ->and($history[0]->to_date->toDateString())->toBe('2026-06-14')
        ->and($history[1]->from_date->toDateString())->toBe('2026-06-15')
        ->and($history[1]->to_date)->toBeNull()
        ->and($history[1]->source_id)->toBe($m->id);
});

// B8 #28
it('keeps a future movement unapplied until the daily job runs on its date', function () {
    $m = movement(['type' => 'transfer', 'effective_date' => '2026-07-01', 'new_department_id' => $this->newDept->id]);
    $this->actions->submit($m, $this->hr);
    expect($m->fresh()->isApplied())->toBeFalse();

    (new ApplyDueEmployeeMovements)->handle($this->actions);
    expect($m->fresh()->isApplied())->toBeFalse();

    $this->travelTo(now()->setDate(2026, 7, 1));
    (new ApplyDueEmployeeMovements)->handle($this->actions);
    expect($m->fresh()->isApplied())->toBeTrue()->and($this->employee->fresh()->department_id)->toBe($this->newDept->id);
});

// B8 #29
it('cancels only the latest applied movement and restores the previous values', function () {
    $originalDept = $this->employee->department_id;
    $first = movement(['type' => 'transfer', 'effective_date' => '2026-06-01', 'new_department_id' => $this->newDept->id]);
    $this->actions->submit($first, $this->hr);
    $designation = Designation::factory()->create();
    $second = movement(['type' => 'redesignation', 'effective_date' => '2026-06-10', 'new_designation_id' => $designation->id]);
    $this->actions->submit($second, $this->hr);

    expect(fn () => $this->actions->cancel($first->fresh(), $this->hr, 'Silap'))->toThrow(ValidationException::class);

    $this->actions->cancel($second->fresh(), $this->hr, 'Silap');
    $this->actions->cancel($first->fresh(), $this->hr, 'Silap');

    $history = EmployeeWorkHistory::query()->where('employee_id', $this->employee->id)->get();
    expect($this->employee->fresh()->department_id)->toBe($originalDept)
        ->and($first->fresh()->status)->toBe(DocumentStatus::Cancelled)
        ->and($history)->toHaveCount(1)
        ->and($history[0]->to_date)->toBeNull();
});

// B8 #30
it('cancels and duplicates ("Batal & Salin") with amended_from_id', function () {
    $m = movement(['type' => 'transfer', 'effective_date' => '2026-07-01', 'new_department_id' => $this->newDept->id]);
    $this->actions->submit($m, $this->hr);

    actingInOrg($this->hr, $this->org)->post("/employee-movements/{$m->id}/cancel-duplicate", ['reason' => 'Tarikh salah'])->assertRedirect();

    $copy = EmployeeMovement::query()->where('amended_from_id', $m->id)->firstOrFail();
    expect($m->fresh()->status)->toBe(DocumentStatus::Cancelled)
        ->and($m->fresh()->cancel_reason)->toBe('Tarikh salah')
        ->and($copy->status)->toBe(DocumentStatus::Draft)
        ->and($copy->new_department_id)->toBe($this->newDept->id)
        ->and($copy->number)->not->toBe($m->number);
});

it('warns on a promotion without a higher grade and rejects a movement that changes nothing', function () {
    $low = Grade::factory()->create(['rank' => 10]);
    $lower = Grade::factory()->create(['rank' => 5]);
    $this->employee->update(['grade_id' => $low->id]);

    $result = $this->actions->saveDraft(null, ['employee_id' => $this->employee->id, 'type' => 'promotion', 'effective_date' => '2026-07-01', 'new_grade_id' => $lower->id]);
    expect($result['warnings'])->not->toBeEmpty()
        ->and(fn () => $this->actions->saveDraft(null, ['employee_id' => $this->employee->id, 'type' => 'transfer', 'effective_date' => '2026-07-01']))
        ->toThrow(ValidationException::class);
});

// B8 #31
it('blocks separation while direct reports exist and allows it after bulk reassignment', function () {
    $report = employee(['reports_to_id' => $this->employee->id]);
    $newBoss = employee();

    actingInOrg($this->hr, $this->org)->post("/employees/{$this->employee->id}/separation", [
        'relieving_date' => '2026-06-30', 'separation_reason' => 'resignation',
    ])->assertSessionHasErrors('reports');

    $this->post("/employees/{$this->employee->id}/reassign-reports", ['reports_to_id' => $newBoss->id])->assertSessionHasNoErrors();
    expect($report->fresh()->reports_to_id)->toBe($newBoss->id);

    $this->post("/employees/{$this->employee->id}/separation", [
        'relieving_date' => '2026-06-30', 'separation_reason' => 'resignation',
    ])->assertSessionHasNoErrors();
    expect($this->employee->fresh()->relieving_date->toDateString())->toBe('2026-06-30')
        ->and($this->employee->fresh()->status->value)->toBe('active');
});

// B8 #32
it('ends access after the relieving date and processes the separation', function () {
    Event::fake([EmployeeSeparated::class]);
    $user = userIn($this->org, 'kakitangan');
    $this->employee->update(['user_id' => $user->id, 'relieving_date' => '2026-06-15', 'separation_reason' => 'resignation']);

    actingInOrg($user, $this->org)->get('/me')->assertOk();

    $this->travelTo(now()->setDate(2026, 6, 16));
    $this->get('/me')->assertRedirect('/login');
    $this->assertGuest();

    (new ProcessDueSeparations)->handle(app(ProcessSeparation::class));

    $e = $this->employee->fresh();
    expect($e->status->value)->toBe('left')
        ->and($e->workHistory()->whereNull('to_date')->count())->toBe(0)
        ->and(app(RoleService::class)->hasRole($user, $this->org->id, 'kakitangan'))->toBeFalse()
        ->and($user->fresh()->is_active)->toBeFalse();
    Event::assertDispatched(EmployeeSeparated::class);
});

it('keeps the login active when the user still belongs to another organisation', function () {
    $user = userIn($this->org, 'kakitangan');
    $other = makeOrganisation();
    app(RoleService::class)->assign($user, $other->id, 'kakitangan');
    inOrg($this->org);
    $this->employee->update(['user_id' => $user->id, 'relieving_date' => '2026-06-10', 'separation_reason' => 'resignation']);

    app(ProcessSeparation::class)->handle($this->employee->fresh());
    expect($user->fresh()->is_active)->toBeTrue();
});

// B8 #32a
it('rehires into a new record linked to the previous one and asks for a YTD review in the same year', function () {
    $user = userIn($this->org, 'kakitangan');
    $this->employee->update(['user_id' => $user->id, 'relieving_date' => '2026-03-31', 'separation_reason' => 'resignation', 'status' => 'left']);

    actingInOrg($this->hr, $this->org)->post("/employees/{$this->employee->id}/rehire", [
        'date_of_joining' => '2026-06-15',
        'department_id' => $this->newDept->id,
        'designation_id' => $this->employee->designation_id,
        'employment_type_id' => $this->employee->employment_type_id,
    ])->assertSessionHas('warnings', fn ($w) => in_array(__('core.rehire.review_ytd'), $w, true));

    $new = Employee::query()->where('previous_employee_id', $this->employee->id)->firstOrFail();
    expect($new->user_id)->toBe($user->id)
        ->and($this->employee->fresh()->user_id)->toBeNull()
        ->and($new->continuous_service_from)->toBeNull()
        ->and($new->employee_no)->not->toBe($this->employee->employee_no);
});

// B8 #33, #61f
it('transfers an employee between organisations and rejects an actor without rights in the target', function () {
    $target = makeOrganisation();
    inOrg($this->org);
    $user = userIn($this->org, 'kakitangan');
    $this->employee->update(['user_id' => $user->id, 'continuous_service_from' => '2018-03-01']);
    $m = movement(['type' => 'inter_org_transfer', 'effective_date' => '2026-06-15', 'target_organisation_id' => $target->id]);

    expect(fn () => $this->actions->submit($m, $this->hr))->toThrow(ValidationException::class);

    app(RoleService::class)->assign($this->hr, $target->id, 'pengurus_hr');
    OrganisationContext::run($target, fn () => Designation::factory()->create());
    $this->actions->submit($m->fresh(), $this->hr);

    $new = Employee::acrossOrganisations()->where('organisation_id', $target->id)->firstOrFail();
    expect($new->previous_employee_id)->toBe($this->employee->id)
        ->and($new->continuous_service_from->toDateString())->toBe('2018-03-01')
        ->and($new->user_id)->toBe($user->id)
        ->and($this->employee->fresh()->relieving_date->toDateString())->toBe('2026-06-14')
        ->and($this->employee->fresh()->separation_reason->value)->toBe('transfer_out')
        ->and($this->employee->fresh()->status->value)->toBe('left');
});

it('renders the movement screens', function () {
    $m = movement(['type' => 'transfer', 'effective_date' => '2026-07-01', 'new_department_id' => $this->newDept->id]);
    actingInOrg($this->hr, $this->org)->get('/employee-movements')->assertOk();
    $this->get('/employee-movements/create')->assertOk();
    $this->get("/employee-movements/{$m->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Core/Movements/Show'));
    $this->get("/employee-movements/{$m->id}/edit")->assertOk();
    $this->post("/employee-movements/{$m->id}/submit")->assertSessionHasNoErrors();
});
