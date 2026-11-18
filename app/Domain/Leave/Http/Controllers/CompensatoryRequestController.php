<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Actions\CompensatoryRequestActions;
use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Http\LeavePresenter;
use App\Domain\Leave\Http\Requests\CompensatoryRequestRequest;
use App\Domain\Leave\Http\Requests\ReasonRequest;
use App\Domain\Leave\Models\CompensatoryLeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permohonan Cuti Gantian (spec-02 §2.11, §3.16, §6.3).
 */
final class CompensatoryRequestController extends Controller
{
    public function __construct(private readonly CompensatoryRequestActions $actions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $hr = $user->can('leave.application.view-all');
        $own = Employee::query()->where('user_id', $user->id)->pluck('id')->all();

        $requests = CompensatoryLeaveRequest::query()->with('employee')
            ->when(! $hr, fn ($q) => $q->whereIn('employee_id', $own))
            ->orderByDesc('work_from')->paginate(25)->withQueryString()
            ->through(fn (CompensatoryLeaveRequest $r) => LeavePresenter::compensatory($r));

        return Inertia::render('Leave/Compensatory/Index', [
            'requests' => $requests,
            'options' => [
                'types' => LeaveType::query()->where('is_active', true)->where('entitlement_basis', EntitlementBasis::Compensatory->value)
                    ->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->displayName()])->all(),
                'employees' => $user->can('leave.application.create-for-others')
                    ? Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])->toArray() : [],
            ],
            'can' => ['request' => $user->can('leave.compensatory.request') || $user->can('leave.application.create-for-others')],
        ]);
    }

    public function store(CompensatoryRequestRequest $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $draft = $this->actions->saveDraft(null, $employee, $request->validated());
        if ($request->boolean('submit')) {
            $this->actions->submit($draft, $request->user(), (array) $request->validated('choices', []));
        }

        return $this->done($request->boolean('submit') ? 'leave.messages.submitted' : 'common.saved');
    }

    public function update(CompensatoryRequestRequest $request, CompensatoryLeaveRequest $compensatory): RedirectResponse
    {
        $this->authorizeOwner($request, $compensatory);
        $employee = Employee::query()->findOrFail($compensatory->employee_id);
        $this->actions->saveDraft($compensatory, $employee, $request->validated());
        if ($request->boolean('submit')) {
            $this->actions->submit($compensatory, $request->user(), (array) $request->validated('choices', []));
        }

        return $this->done('common.saved');
    }

    public function submit(Request $request, CompensatoryLeaveRequest $compensatory): RedirectResponse
    {
        $this->authorizeOwner($request, $compensatory);
        $this->actions->submit($compensatory, $request->user(), (array) $request->input('choices', []));

        return $this->done('leave.messages.submitted');
    }

    public function cancel(ReasonRequest $request, CompensatoryLeaveRequest $compensatory): RedirectResponse
    {
        $user = $request->user();
        $isOwner = (int) Employee::query()->whereKey($compensatory->employee_id)->value('user_id') === $user->id;
        $allowed = $compensatory->status === ApprovalStatus::Pending
            ? $isOwner || $user->can('leave.application.create-for-others')
            : $user->can('leave.application.cancel-approved') && $user->can('leave.application.create-for-others');
        abort_unless($allowed, 403);
        $this->actions->cancel($compensatory, $user, (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function destroy(Request $request, CompensatoryLeaveRequest $compensatory): RedirectResponse
    {
        $this->authorizeOwner($request, $compensatory);
        $compensatory->delete();

        return $this->done('common.deleted');
    }

    private function employee(CompensatoryRequestRequest $request): Employee
    {
        $id = $request->validated('employee_id');
        $user = $request->user();
        if ($id === null) {
            $employee = Employee::query()->where('user_id', $user->id)->first();
            abort_if($employee === null || ! ($user->can('leave.compensatory.request') || $user->can('leave.application.create-for-others')), 403);

            return $employee;
        }
        $employee = Employee::query()->findOrFail($id);
        abort_unless($employee->user_id === $user->id || $user->can('leave.application.create-for-others'), 403);

        return $employee;
    }

    private function authorizeOwner(Request $request, CompensatoryLeaveRequest $compensatory): void
    {
        $user = $request->user();
        abort_unless($compensatory->isDraft(), 403);
        $isOwner = (int) Employee::query()->whereKey($compensatory->employee_id)->value('user_id') === $user->id;
        abort_unless($isOwner || $user->can('leave.application.create-for-others'), 403);
    }
}
