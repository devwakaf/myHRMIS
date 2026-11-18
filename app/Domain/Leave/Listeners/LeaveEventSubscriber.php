<?php

declare(strict_types=1);

namespace App\Domain\Leave\Listeners;

use App\Domain\Core\Enums\AssignableType;
use App\Domain\Core\Events\EmployeeHired;
use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Core\Events\HolidayAssignmentChanged;
use App\Domain\Core\Events\HolidayListChanged;
use App\Domain\Core\Events\OrganisationCreated;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Leave\Actions\PolicyAssignmentActions;
use App\Domain\Leave\Actions\ProcessLeaveSeparation;
use App\Domain\Leave\Actions\RecalculateLeaveDays;
use App\Domain\Leave\Actions\SeedLeaveDefaults;
use App\Domain\Leave\Events\LeaveEncashmentPayoutChanged;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Leave's reactions to Core events (spec-02 §5.4): defaults for new
 * organisations, default policy on hire, separation (§3.7) and holiday changes
 * (§3.14). Ledger effects of approvals are NOT here — they run in the
 * Approvable hooks.
 */
final class LeaveEventSubscriber
{
    public function onOrganisationCreated(OrganisationCreated $event): void
    {
        app(SeedLeaveDefaults::class)->handle($event->organisation->id);
    }

    public function onEmployeeHired(EmployeeHired $event): void
    {
        $employee = $event->employee;
        OrganisationContext::run((int) $employee->organisation_id, function () use ($employee): void {
            $policyId = LeaveSettings::current((int) $employee->organisation_id)->default_leave_policy_id;
            $exists = LeavePolicyAssignment::query()->where('employee_id', $employee->id)
                ->where('status', '!=', DocumentStatus::Cancelled->value)->exists();
            if ($policyId === null || $exists) {
                return;
            }

            $actions = app(PolicyAssignmentActions::class);
            $draft = $actions->saveDraft(null, [
                'employee_id' => $employee->id,
                'leave_policy_id' => $policyId,
                'effective_from' => $employee->date_of_joining->toDateString(),
            ]);
            $actions->submit($draft, null);
        });
    }

    public function onEmployeeSeparated(EmployeeSeparated $event): void
    {
        $employee = $event->employee;
        OrganisationContext::run((int) $employee->organisation_id, function () use ($employee): void {
            app(ProcessLeaveSeparation::class)->handle(Employee::query()->findOrFail($employee->id));
        });
    }

    public function onHolidayListChanged(HolidayListChanged $event): void
    {
        $dates = array_values(array_unique(array_merge($event->addedDates, $event->removedDates)));
        if ($dates === []) {
            return;
        }

        $orgId = HolidayList::acrossOrganisations()->whereKey($event->holidayListId)->value('organisation_id');
        if ($orgId === null) {
            return;
        }

        OrganisationContext::run((int) $orgId, function () use ($event, $dates): void {
            $ids = DB::table('leave_application_days as d')
                ->join('leave_applications as a', 'a.id', '=', 'd.leave_application_id')
                ->where('d.holiday_list_id', $event->holidayListId)
                ->whereIn('d.date', $dates)
                ->whereIn('a.status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value, ApprovalStatus::Draft->value])
                ->distinct()->pluck('a.id');

            $this->recalculate($ids->map(fn ($id) => (int) $id)->all(), 'holiday_list:'.$event->holidayListId);
        });
    }

    public function onHolidayAssignmentChanged(HolidayAssignmentChanged $event): void
    {
        $assignment = $event->assignment;
        OrganisationContext::run((int) $assignment->organisation_id, function () use ($assignment): void {
            $employeeIds = match ($assignment->assignable_type) {
                AssignableType::Employee => [$assignment->assignable_id],
                AssignableType::Branch => Employee::query()->where('branch_id', $assignment->assignable_id)->pluck('id')->all(),
                AssignableType::Organisation => null,
            };

            $ids = LeaveApplication::query()
                ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
                ->whereDate('to_date', '>=', $assignment->effective_from->toDateString())
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            $this->recalculate($ids, 'holiday_assignment:'.$assignment->id);
        });
    }

    /** Payroll reported a payout status for an encashment (spec-02 §5.3). */
    public function onEncashmentPayoutChanged(LeaveEncashmentPayoutChanged $event): void
    {
        $orgId = LeaveEncashment::acrossOrganisations()->whereKey($event->encashmentId)->value('organisation_id');
        if ($orgId === null) {
            return;
        }

        OrganisationContext::run((int) $orgId, function () use ($event): void {
            $encashment = LeaveEncashment::query()->find($event->encashmentId);
            $encashment?->forceFill(['payout_status' => $event->status])->save();
        });
    }

    /**
     * @param  list<int>  $ids
     */
    private function recalculate(array $ids, string $trigger): void
    {
        $action = app(RecalculateLeaveDays::class);
        foreach (LeaveApplication::query()->whereIn('id', $ids)->orderBy('id')->get() as $application) {
            $action->handle($application, $trigger);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrganisationCreated::class => 'onOrganisationCreated',
            EmployeeHired::class => 'onEmployeeHired',
            EmployeeSeparated::class => 'onEmployeeSeparated',
            HolidayListChanged::class => 'onHolidayListChanged',
            HolidayAssignmentChanged::class => 'onHolidayAssignmentChanged',
            LeaveEncashmentPayoutChanged::class => 'onEncashmentPayoutChanged',
        ];
    }
}
