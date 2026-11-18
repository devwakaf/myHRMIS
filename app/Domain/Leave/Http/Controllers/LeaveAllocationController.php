<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Core\Http\Resources\Lookup;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Actions\AdjustmentActions;
use App\Domain\Leave\Actions\AllocationActions;
use App\Domain\Leave\Actions\EncashmentActions;
use App\Domain\Leave\Actions\PolicyAssignmentActions;
use App\Domain\Leave\Enums\EncashmentPurpose;
use App\Domain\Leave\Enums\EncashmentRateBasis;
use App\Domain\Leave\Http\LeavePresenter;
use App\Domain\Leave\Http\Requests\AdjustmentRequest;
use App\Domain\Leave\Http\Requests\AllocationRequest;
use App\Domain\Leave\Http\Requests\EncashmentRequest;
use App\Domain\Leave\Http\Requests\PolicyAssignmentRequest;
use App\Domain\Leave\Http\Requests\ReasonRequest;
use App\Domain\Leave\Models\LeaveAdjustment;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeaveReviewTask;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR ledger screens (spec-02 §6.3): Penetapan Leave Policy (+ Penetapan Pukal),
 * Peruntukan Cuti, Pelarasan Cuti, Gantian Tunai Cuti, Jadual Kredit Cuti and
 * the "Permohonan Perlu Semakan" queue.
 */
final class LeaveAllocationController extends Controller
{
    // ------------------------------------------------------------------ policy assignments

    public function assignments(Request $request): Response
    {
        $this->requirePermission('leave.allocation.manage');

        return Inertia::render('Leave/PolicyAssignments/Index', [
            'assignments' => LeavePolicyAssignment::query()->with(['employee', 'policy'])
                ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
                ->orderByDesc('effective_from')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (LeavePolicyAssignment $a) => [
                    'id' => $a->id, 'number' => $a->number, 'employee_id' => $a->employee_id, 'employee' => $a->employee->full_name,
                    'leave_policy_id' => $a->leave_policy_id, 'policy' => $a->policy->name,
                    'effective_from' => $a->effective_from->toDateString(), 'effective_to' => $a->effective_to?->toDateString(),
                    'status' => $a->status->value, 'status_label' => $a->status->label(), 'remarks' => $a->remarks,
                ]),
            'options' => $this->employeeAndPolicyOptions() + array_intersect_key(Lookup::employeeForm(), array_flip(['branches', 'departments', 'employment_types'])),
        ]);
    }

    public function storeAssignment(PolicyAssignmentRequest $request, PolicyAssignmentActions $actions): RedirectResponse|JsonResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $data = $request->validated();

        if ($request->boolean('bulk')) {
            $policy = LeavePolicy::query()->findOrFail($data['leave_policy_id']);
            $results = $actions->bulkAssign($policy, $data['effective_from'], $data, $request->user(), $request->boolean('preview'));

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['results' => $results])
                : back()->with('success', __('leave.messages.bulk_done', ['count' => count(array_filter($results, fn ($r) => $r['ok']))]))->with('bulk_results', $results);
        }

        $draft = $actions->saveDraft(null, ['employee_id' => (int) $data['employee_id'], 'leave_policy_id' => (int) $data['leave_policy_id'], 'effective_from' => $data['effective_from'], 'remarks' => $data['remarks'] ?? null]);
        $warnings = $request->boolean('submit') ? $actions->submit($draft, $request->user()) : [];

        return $this->done('common.saved', $warnings);
    }

    public function submitAssignment(Request $request, LeavePolicyAssignment $assignment, PolicyAssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');

        return $this->done('common.submitted', $actions->submit($assignment, $request->user()));
    }

    public function cancelAssignment(ReasonRequest $request, LeavePolicyAssignment $assignment, PolicyAssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $actions->cancel($assignment, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function destroyAssignment(LeavePolicyAssignment $assignment): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $assignment->delete();

        return $this->done('common.deleted');
    }

    // ------------------------------------------------------------------ allocations

    public function allocations(Request $request): Response
    {
        $this->requirePermission('leave.allocation.manage');

        return Inertia::render('Leave/Allocations/Index', [
            'allocations' => LeaveAllocation::query()->with(['employee', 'leaveType', 'period'])
                ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
                ->when($request->query('leave_type_id'), fn ($q, $v) => $q->where('leave_type_id', $v))
                ->when($request->query('leave_period_id'), fn ($q, $v) => $q->where('leave_period_id', $v))
                ->orderByDesc('from_date')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (LeaveAllocation $a) => LeavePresenter::allocation($a)),
            'filters' => $request->only(['employee_id', 'leave_type_id', 'leave_period_id']),
            'options' => $this->employeeAndPolicyOptions(),
        ]);
    }

    public function showAllocation(LeaveAllocation $allocation): Response
    {
        $this->requirePermission('leave.allocation.manage');
        $allocation->load(['employee', 'leaveType', 'period']);

        return Inertia::render('Leave/Allocations/Show', ['allocation' => LeavePresenter::allocation($allocation, true)]);
    }

    public function storeAllocation(AllocationRequest $request, AllocationActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $allocation = $actions->saveManualDraft(null, $request->validated());
        if ($request->boolean('submit')) {
            $actions->submitManual($allocation, $request->user());
        }

        return $this->done('common.saved');
    }

    public function submitAllocation(Request $request, LeaveAllocation $allocation, AllocationActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $actions->submitManual($allocation, $request->user());

        return $this->done('common.submitted');
    }

    public function cancelAllocation(ReasonRequest $request, LeaveAllocation $allocation, AllocationActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $actions->cancel($allocation, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function destroyAllocation(LeaveAllocation $allocation): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $allocation->delete();

        return $this->done('common.deleted');
    }

    /** "Kira Semula Bawa Ke Hadapan" */
    public function recomputeCarry(LeaveAllocation $allocation, AllocationActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $days = $actions->recomputeCarryForward($allocation);

        return back()->with('success', __('leave.messages.carry_recomputed', ['days' => $days]));
    }

    // ------------------------------------------------------------------ credit schedules

    public function creditSchedules(Request $request): Response
    {
        $this->requirePermission('leave.allocation.manage');

        return Inertia::render('Leave/CreditSchedules/Index', [
            'schedules' => LeaveCreditSchedule::query()->with('allocation.employee', 'allocation.leaveType')
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->orderByDesc('due_date')->paginate(50)->withQueryString()
                ->through(fn (LeaveCreditSchedule $s) => [
                    'id' => $s->id, 'leave_allocation_id' => $s->leave_allocation_id,
                    'employee' => $s->allocation->employee->full_name, 'leave_type' => $s->allocation->leaveType->displayName(),
                    'due_date' => $s->due_date->toDateString(), 'kind' => $s->kind->value, 'kind_label' => $s->kind->label(),
                    'days' => (string) $s->days, 'status' => $s->status->value, 'status_label' => $s->status->label(),
                    'failure_reason' => $s->failure_reason, 'posted_via' => $s->posted_via?->value,
                ]),
            'filters' => $request->only(['status']),
        ]);
    }

    public function retryCredit(LeaveCreditSchedule $schedule, AllocationActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $status = $actions->retryCredit($schedule);

        return back()->with('success', __('leave.messages.credit_retried', ['status' => $status->label()]));
    }

    // ------------------------------------------------------------------ adjustments

    public function adjustments(Request $request): Response
    {
        $this->requirePermission('leave.allocation.manage', 'leave.adjustment.submit');

        return Inertia::render('Leave/Adjustments/Index', [
            'adjustments' => LeaveAdjustment::query()->with('allocation.employee')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (LeaveAdjustment $a) => LeavePresenter::adjustment($a) + ['employee' => $a->allocation->employee->full_name]),
            'can' => ['submit' => $request->user()->can('leave.adjustment.submit')],
            'options' => ['allocations' => $this->allocationOptions(false)],
        ]);
    }

    public function storeAdjustment(AdjustmentRequest $request, AdjustmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage', 'leave.adjustment.submit');
        $adjustment = $actions->saveDraft(null, $request->validated());
        if ($request->boolean('submit')) {
            $this->requirePermission('leave.adjustment.submit');
            $actions->submit($adjustment, $request->user());
        }

        return $this->done('common.saved');
    }

    public function submitAdjustment(Request $request, LeaveAdjustment $adjustment, AdjustmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.adjustment.submit');
        $actions->submit($adjustment, $request->user());

        return $this->done('common.submitted');
    }

    public function cancelAdjustment(ReasonRequest $request, LeaveAdjustment $adjustment, AdjustmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.adjustment.submit');
        $actions->cancel($adjustment, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function destroyAdjustment(LeaveAdjustment $adjustment): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage', 'leave.adjustment.submit');
        $adjustment->delete();

        return $this->done('common.deleted');
    }

    // ------------------------------------------------------------------ encashments

    public function encashments(Request $request): Response
    {
        $this->requirePermission('leave.encashment.manage', 'leave.encashment.view');

        return Inertia::render('Leave/Encashments/Index', [
            'encashments' => LeaveEncashment::query()->with('employee')->orderByDesc('encashment_date')->orderByDesc('id')
                ->paginate(25)->withQueryString()->through(fn (LeaveEncashment $e) => LeavePresenter::encashment($e)),
            'can' => ['manage' => $request->user()->can('leave.encashment.manage'), 'submit' => $request->user()->can('leave.encashment.submit')],
            'options' => [
                'allocations' => $this->allocationOptions(true),
                'rate_bases' => EncashmentRateBasis::options(),
                'purposes' => EncashmentPurpose::options(),
                'default_rate_basis' => LeaveSettings::current()->encashment_rate_basis->value,
            ],
        ]);
    }

    /** JSON: eligible days and the computed amount for the form. */
    public function previewEncashment(EncashmentRequest $request, EncashmentActions $actions): JsonResponse
    {
        $this->requirePermission('leave.encashment.manage');
        $data = $request->validated();
        $allocation = LeaveAllocation::query()->live()->with('leaveType')->findOrFail($data['leave_allocation_id']);
        $date = CarbonImmutable::parse($data['encashment_date']);
        $eligible = $actions->eligibleDays($allocation, $date);
        $employee = Employee::query()->findOrFail($allocation->employee_id);
        $rate = isset($data['monthly_basic'])
            ? $actions->rate($employee, (string) $data['monthly_basic'], (string) $data['days'], EncashmentRateBasis::tryFrom((string) ($data['rate_basis'] ?? '')) ?? LeaveSettings::current()->encashment_rate_basis, $date)
            : null;

        return response()->json(['eligible_days' => $eligible, 'rate' => $rate]);
    }

    public function storeEncashment(EncashmentRequest $request, EncashmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.encashment.manage');
        $result = $actions->saveDraft(null, $request->validated());
        if ($request->boolean('submit')) {
            $this->requirePermission('leave.encashment.submit');
            $actions->submit($result['encashment'], $request->user());
        }

        return $this->done('common.saved', $result['warnings']);
    }

    public function submitEncashment(Request $request, LeaveEncashment $encashment, EncashmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.encashment.submit');
        $actions->submit($encashment, $request->user());

        return $this->done('common.submitted');
    }

    public function cancelEncashment(ReasonRequest $request, LeaveEncashment $encashment, EncashmentActions $actions): RedirectResponse
    {
        $this->requirePermission('leave.encashment.submit');
        $actions->cancel($encashment, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function destroyEncashment(LeaveEncashment $encashment): RedirectResponse
    {
        $this->requirePermission('leave.encashment.manage');
        $encashment->delete();

        return $this->done('common.deleted');
    }

    // ------------------------------------------------------------------ review queue

    public function reviewTasks(Request $request): Response
    {
        $this->requirePermission('leave.allocation.manage');

        return Inertia::render('Leave/ReviewTasks/Index', [
            'tasks' => LeaveReviewTask::query()->with('application.employee', 'application.leaveType')
                ->when(! $request->boolean('all'), fn ($q) => $q->whereNull('resolved_at'))
                ->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (LeaveReviewTask $t) => [
                    'id' => $t->id, 'reason' => $t->reason, 'reason_label' => __('leave.review_reasons.'.$t->reason),
                    'details' => $t->details, 'resolved_at' => $t->resolved_at?->format('Y-m-d H:i'), 'resolution_note' => $t->resolution_note,
                    'application' => LeavePresenter::application($t->application),
                ]),
        ]);
    }

    public function resolveReviewTask(Request $request, LeaveReviewTask $task): RedirectResponse
    {
        $this->requirePermission('leave.allocation.manage');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $task->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id, 'resolution_note' => $data['note'] ?? null]);

        return $this->done('common.saved');
    }

    /**
     * Submitted allocations as picker options ("Employee · Type · Period").
     *
     * @return list<array{value: int, label: string}>
     */
    private function allocationOptions(bool $encashableOnly): array
    {
        return LeaveAllocation::query()->live()->with(['employee:id,full_name', 'leaveType', 'period:id,name'])
            ->when($encashableOnly, fn ($q) => $q->whereHas('leaveType', fn ($t) => $t->where('allow_encashment', true)))
            ->orderByDesc('from_date')->limit(500)->get()
            ->map(fn (LeaveAllocation $a) => ['value' => $a->id, 'label' => $a->employee->full_name.' · '.$a->leaveType->displayName().' · '.$a->period->name])
            ->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeAndPolicyOptions(): array
    {
        return [
            'employees' => Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])->toArray(),
            'policies' => LeavePolicy::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'types' => LeaveType::query()->orderBy('sort_order')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->displayName(), 'has_balance' => $t->hasBalance()])->all(),
            'periods' => LeavePeriod::query()->orderByDesc('from_date')->get(['id', 'name', 'from_date', 'to_date'])->toArray(),
            'statuses' => DocumentStatus::options(),
        ];
    }
}
