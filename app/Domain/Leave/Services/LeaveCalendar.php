<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\CalendarVisibility;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Approval\Models\ApprovalStepAssignee;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data behind Kalendar Cuti (spec-02 §6.2.4): HR sees everyone; a Pelulus sees
 * applicants assigned to them; a Kakitangan sees own leave and — only when the
 * setting allows — colleagues of the same department by name (no type/reason).
 */
final class LeaveCalendar
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * @return list<array{application_id: int|null, employee: string, from: string, to: string, status: string, type_code: string|null, colour: string|null, own: bool}>
     */
    public function entries(User $viewer, int $organisationId, DateRange $range): array
    {
        $to = ($range->to ?? $range->from)->toDateString();
        $base = fn (): Builder => LeaveApplication::query()->with(['employee:id,full_name,preferred_name,department_id,user_id', 'leaveType:id,code,colour'])
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $range->from->toDateString());

        $own = Employee::query()->where('user_id', $viewer->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $full = [];
        $namesOnly = [];

        if ($viewer->is_super_admin || $this->roles->can($viewer, $organisationId, 'leave.application.view-all')) {
            $full = $base()->get()->all();
        } else {
            $assigned = ApprovalStepAssignee::query()->where('user_id', $viewer->id)
                ->whereHas('step', fn ($q) => $q->where('status', '!=', StepStatus::Waiting->value))
                ->with('step.request:id,approvable_type,approvable_id')->get()
                ->map(fn (ApprovalStepAssignee $a) => $a->step?->request)
                ->filter(fn ($r) => $r !== null && $r->approvable_type === (new LeaveApplication)->getMorphClass())
                ->map(fn ($r) => (int) $r->approvable_id)->unique()->values()->all();

            $full = $base()->where(fn (Builder $q) => $q->whereIn('employee_id', $own)->orWhereIn('id', $assigned))->get()->all();

            if (LeaveSettings::current($organisationId)->calendar_colleague_visibility === CalendarVisibility::DepartmentNamesOnly && $own !== []) {
                $departments = Employee::query()->whereIn('id', $own)->pluck('department_id')->all();
                $namesOnly = $base()->where('status', ApprovalStatus::Approved->value)
                    ->whereNotIn('employee_id', $own)
                    ->whereHas('employee', fn (Builder $q) => $q->whereIn('department_id', $departments))
                    ->whereNotIn('id', array_map(fn (LeaveApplication $a) => $a->id, $full))
                    ->get()->all();
            }
        }

        $out = [];
        foreach ($full as $application) {
            $out[] = [
                'application_id' => $application->id,
                'employee' => $application->employee->displayName(),
                'from' => $application->from_date->toDateString(),
                'to' => $application->to_date->toDateString(),
                'status' => $application->status->value,
                'type_code' => $application->leaveType->code,
                'colour' => $application->leaveType->colour,
                'own' => in_array($application->employee_id, $own, true),
            ];
        }
        foreach ($namesOnly as $application) {
            $out[] = [
                'application_id' => null,
                'employee' => $application->employee->displayName(),
                'from' => $application->from_date->toDateString(),
                'to' => $application->to_date->toDateString(),
                'status' => $application->status->value,
                'type_code' => null,
                'colour' => null,
                'own' => false,
            ];
        }

        return $out;
    }
}
