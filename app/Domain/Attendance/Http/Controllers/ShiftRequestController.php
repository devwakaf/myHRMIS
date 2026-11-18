<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Actions\ShiftRequestActions;
use App\Domain\Attendance\Http\AttendancePresenter;
use App\Domain\Attendance\Http\Requests\ReasonRequest;
use App\Domain\Attendance\Http\Requests\ShiftRequestRequest;
use App\Domain\Attendance\Models\ShiftRequest;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\ShiftRequestApproval;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalPresenter;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permohonan Shift (spec-03 §2.4, §4.8).
 */
final class ShiftRequestController extends Controller
{
    public function __construct(private readonly ShiftRequestActions $actions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $all = $user->can('attendance.view-all');
        $own = Employee::query()->where('user_id', $user->id)->pluck('id')->all();

        return Inertia::render('Attendance/ShiftRequests/Index', [
            'requests' => ShiftRequest::query()->with(['employee', 'shiftType'])
                ->when(! $all, fn ($q) => $q->whereIn('employee_id', $own))
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('from_date')->paginate(25)->withQueryString()
                ->through(fn (ShiftRequest $r) => AttendancePresenter::shiftRequest($r)),
            'filters' => $request->only(['status']),
            'options' => [
                'shift_types' => ShiftType::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'start_time', 'end_time', 'colour'])->toArray(),
                'employees' => $user->can('attendance.request.create-for-others')
                    ? Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])->toArray() : [],
                'max_days' => ShiftRequestActions::MAX_SPAN_DAYS,
            ],
            'own_employee_id' => $own[0] ?? null,
            'can' => [
                'create' => $user->can('attendance.shift-request.create') || $user->can('attendance.request.create-for-others'),
                'create_for_others' => $user->can('attendance.request.create-for-others'),
            ],
        ]);
    }

    public function store(ShiftRequestRequest $request): RedirectResponse
    {
        $employee = $this->targetEmployee($request);
        $doc = $this->actions->saveDraft(null, $employee, $request->validated());
        if ($request->boolean('submit')) {
            $this->actions->submit($doc, $request->user(), (array) $request->validated('choices', []));
        }

        return $this->done($request->boolean('submit') ? 'attendance.messages.submitted' : 'common.saved', [], '/shift-requests/'.$doc->id);
    }

    public function show(Request $request, ShiftRequest $shiftRequest, ApprovalPresenter $approvals, ShiftRequestApproval $hooks): Response
    {
        $this->authorize('view', $shiftRequest);
        $user = $request->user();
        $approval = $shiftRequest->approvalRequest()->first();
        $shiftRequest->load(['employee', 'shiftType']);

        return Inertia::render('Attendance/ShiftRequests/Show', [
            'request' => AttendancePresenter::shiftRequest($shiftRequest),
            'context' => $hooks->decisionContext($shiftRequest, $user),
            'timeline' => $approval === null ? null : $approvals->timeline($approval),
            'approval_request_id' => $approval?->id,
            'can' => [
                'update' => $user->can('update', $shiftRequest),
                'withdraw' => $user->can('withdraw', $shiftRequest),
                'cancel' => $shiftRequest->status === ApprovalStatus::Approved && $user->can('cancelApproved', $shiftRequest),
            ],
        ]);
    }

    public function update(ShiftRequestRequest $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $this->authorize('update', $shiftRequest);
        $this->actions->saveDraft($shiftRequest, Employee::query()->findOrFail($shiftRequest->employee_id), $request->validated());
        if ($request->boolean('submit')) {
            $this->actions->submit($shiftRequest, $request->user(), (array) $request->validated('choices', []));
        }

        return $this->done($request->boolean('submit') ? 'attendance.messages.submitted' : 'common.saved');
    }

    public function destroy(ShiftRequest $shiftRequest): RedirectResponse
    {
        $this->authorize('update', $shiftRequest);
        $shiftRequest->delete();

        return $this->done('common.deleted', [], '/shift-requests');
    }

    public function submit(Request $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $this->authorize('update', $shiftRequest);
        $this->actions->submit($shiftRequest, $request->user(), (array) $request->input('choices', []));

        return $this->done('attendance.messages.submitted');
    }

    public function withdraw(ReasonRequest $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $this->authorize('withdraw', $shiftRequest);
        $this->actions->withdraw($shiftRequest, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function cancel(ReasonRequest $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $this->authorize('cancelApproved', $shiftRequest);
        $this->actions->cancelApproved($shiftRequest, $request->user(), (string) $request->validated('reason'), $request->user()->can('attendance.manage'));

        return $this->done('common.cancelled');
    }

    private function targetEmployee(ShiftRequestRequest $request): Employee
    {
        $id = $request->validated('employee_id');
        $employee = $id === null
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : Employee::query()->find($id);
        abort_if($employee === null, 403);
        $this->authorize('createFor', [ShiftRequest::class, $employee]);

        return $employee;
    }
}
