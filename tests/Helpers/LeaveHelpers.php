<?php

/*
| Helpers for the M2 (Cuti) test suite. Loaded from tests/Pest.php.
| Calendar facts used by spec-02 §7: 1 June 2026 is a Monday; Sat/Sun weekly off.
*/

use App\Domain\Core\Actions\GenerateWeeklyOffs;
use App\Domain\Core\Actions\HolidayAssignmentActions;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Actions\AllocationActions;
use App\Domain\Leave\Actions\SubmitLeaveApplication;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Shared\Approval\Actions\PublishApprovalWorkflow;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Organisation + HR manager + approver + one-step `leave` and `compensatory_leave` workflows + 2026 holiday list. */
function leaveWorld(string $today = '2026-06-01', array $holidays = [], array $halfDayWeeklyOff = []): stdClass
{
    test()->travelTo(CarbonImmutable::parse($today.' 09:00:00'));

    $w = new stdClass;
    $w->org = inOrg(makeOrganisation());
    $w->hr = userIn($w->org, 'pengurus_hr', ['name' => 'Pengurus HR']);
    $w->approver = userIn($w->org, 'kakitangan', ['name' => 'Pelulus']);
    $w->list = leaveHolidayList($w->hr, $holidays, $halfDayWeeklyOff);
    leaveWorkflow([leaveStep('Pelulus', $w->approver)]);
    leaveWorkflow([leaveStep('Pelulus', $w->approver)], 'compensatory_leave');
    $w->period = LeavePeriod::query()->whereDate('from_date', '2026-01-01')->first()
        ?? LeavePeriod::factory()->create();

    return $w;
}

function leaveHolidayList(User $actor, array $holidays = [], array $halfDayWeeklyOff = [], int $year = 2026): HolidayList
{
    $list = HolidayList::factory()->create([
        'from_date' => "{$year}-01-01", 'to_date' => "{$year}-12-31",
        'weekly_off_days' => array_values(array_diff([6, 7], $halfDayWeeklyOff)),
        'half_day_weekly_off_days' => $halfDayWeeklyOff,
    ]);
    app(GenerateWeeklyOffs::class)->handle($list);
    foreach ($holidays as $date => $name) {
        Holiday::query()->create(['holiday_list_id' => $list->id, 'date' => $date, 'name' => $name, 'kind' => 'organisation', 'is_half_day' => false, 'is_mandatory' => false]);
    }
    $hla = HolidayListAssignment::factory()->create(['holiday_list_id' => $list->id, 'effective_from' => "{$year}-01-01"]);
    app(HolidayAssignmentActions::class)->submit($hla, $actor);

    return $list;
}

/** @return array<string, mixed> */
function leaveStep(string $name, User $user, array $extra = []): array
{
    return array_merge([
        'name' => $name, 'rule_type' => 'specific_user', 'rule_config' => ['user_id' => $user->id], 'mode' => 'any_one',
        'skip_if_applicant' => true, 'skip_if_same_as_previous' => false, 'exclude_submitter' => true,
        'on_no_approver' => 'fail', 'condition' => null,
    ], $extra);
}

function leaveWorkflow(array $steps, string $type = 'leave'): void
{
    app(PublishApprovalWorkflow::class)->handle(OrganisationContext::id(), $type, ['name' => 'Ujian '.$type, 'steps' => $steps]);
}

/** A Kakitangan with a login. */
function staff(array $attributes = [], ?Organisation $org = null): Employee
{
    $user = userIn($org ?? OrganisationContext::current(), 'kakitangan');

    return employee($attributes + ['date_of_joining' => '2020-01-01', 'gender' => 'male', 'marital_status' => 'married'], $user);
}

function leaveTypeMake(array $attributes = []): LeaveType
{
    return LeaveType::factory()->create($attributes);
}

/** Manual allocation (submitted) — the quickest way to give a balance. */
function allocate(Employee $employee, LeaveType $type, string $days, ?LeavePeriod $period = null, ?string $from = null, ?string $to = null): LeaveAllocation
{
    $period ??= LeavePeriod::query()->whereDate('from_date', '2026-01-01')->firstOrFail();
    $actions = app(AllocationActions::class);
    $allocation = $actions->saveManualDraft(null, [
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_period_id' => $period->id,
        'from_date' => $from, 'to_date' => $to, 'initial_days' => $days,
    ]);

    return $actions->submitManual($allocation, User::query()->findOrFail($employee->user_id));
}

/** Save + submit an application as the employee (or $actor). */
function applyLeave(Employee $employee, LeaveType $type, string $from, ?string $to = null, array $extra = [], ?User $actor = null): LeaveApplication
{
    $actor ??= User::query()->findOrFail($employee->user_id);
    $result = app(SubmitLeaveApplication::class)->apply($employee, ['leave_type_id' => $type->id, 'from_date' => $from, 'to_date' => $to ?? $from] + $extra, $actor);
    test()->leaveWarnings = $result['warnings'];

    return $result['application'];
}

function draftLeave(Employee $employee, LeaveType $type, string $from, ?string $to = null, array $extra = [], ?User $actor = null): LeaveApplication
{
    $actor ??= User::query()->findOrFail($employee->user_id);

    return app(SubmitLeaveApplication::class)->saveDraft(null, $employee, ['leave_type_id' => $type->id, 'from_date' => $from, 'to_date' => $to ?? $from] + $extra, $actor);
}

function approveLeave(LeaveApplication $application, User $approver): LeaveApplication
{
    app(ApprovalService::class)->approve($application->approvalRequest()->firstOrFail(), $approver);

    return $application->refresh();
}

function seededType(string $code): LeaveType
{
    return LeaveType::query()->where('code', $code)->firstOrFail();
}
