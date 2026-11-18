<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Actions\AttendanceRequestActions;
use App\Domain\Attendance\Enums\AttendanceRequestKind;
use App\Domain\Attendance\Enums\HalfDaySession;
use App\Domain\Attendance\Http\AttendancePresenter;
use App\Domain\Attendance\Http\Requests\AttendanceRequestRequest;
use App\Domain\Attendance\Http\Requests\ReasonRequest;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\AttendanceRequestApproval;
use App\Domain\Attendance\Services\AttendanceRequestPlanner;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalPresenter;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permohonan Kehadiran — WFH / Tugas Luar (spec-03 §2.3, §4.8).
 */
final class AttendanceRequestController extends Controller
{
    public function __construct(private readonly AttendanceRequestActions $actions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $all = $user->can('attendance.view-all');
        $own = Employee::query()->where('user_id', $user->id)->pluck('id')->all();

        return Inertia::render('Attendance/Requests/Index', [
            'requests' => AttendanceRequest::query()->with('employee')
                ->when(! $all, fn ($q) => $q->whereIn('employee_id', $own))
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('from_date')->paginate(25)->withQueryString()
                ->through(fn (AttendanceRequest $r) => AttendancePresenter::attendanceRequest($r)),
            'filters' => $request->only(['status']),
            'options' => $this->options($user->can('attendance.request.create-for-others')),
            'own_employee_id' => $own[0] ?? null,
            'can' => [
                'create' => $user->can('attendance.request.create') || $user->can('attendance.request.create-for-others'),
                'create_for_others' => $user->can('attendance.request.create-for-others'),
            ],
        ]);
    }

    /** Live per-day preview + approval route for the form (JSON). */
    public function preview(AttendanceRequestRequest $request, AttendanceRequestPlanner $planner, ApprovalService $approvals): JsonResponse
    {
        $employee = $this->targetEmployee($request);
        $draft = new AttendanceRequest(['employee_id' => $employee->id, 'organisation_id' => $employee->organisation_id]);
        $data = $request->validated();
        $isHalf = (bool) ($data['is_half_day'] ?? false);
        $draft->fill([
            'kind' => $data['kind'], 'from_date' => $data['from_date'], 'to_date' => $data['to_date'] ?? $data['from_date'],
            'is_half_day' => $isHalf, 'half_day_date' => $isHalf ? ($data['half_day_date'] ?? $data['from_date']) : null,
            'half_day_session' => $isHalf ? ($data['half_day_session'] ?? 'am') : null,
            'include_rest_days' => (bool) ($data['include_rest_days'] ?? false), 'explanation' => (string) $data['explanation'],
        ]);

        return response()->json([
            'days' => AttendanceRequestApproval::presentPlan($planner->plan($draft)),
            'route' => $approvals->preview($draft, (array) ($data['choices'] ?? []), $request->user())->toArray(),
        ]);
    }

    public function store(AttendanceRequestRequest $request): RedirectResponse
    {
        $employee = $this->targetEmployee($request);
        $doc = $this->actions->saveDraft(null, $employee, $request->validated());
        if ($request->boolean('submit')) {
            $this->actions->submit($doc, $request->user(), (array) $request->validated('choices', []));
        }

        return $this->done($request->boolean('submit') ? 'attendance.messages.submitted' : 'common.saved', [], '/attendance-requests/'.$doc->id);
    }

    public function show(Request $request, AttendanceRequest $attendanceRequest, ApprovalPresenter $approvals, AttendanceRequestPlanner $planner): Response
    {
        $this->authorize('view', $attendanceRequest);
        $user = $request->user();
        $approval = $attendanceRequest->approvalRequest()->first();
        $attendanceRequest->load(['employee', 'days']);

        return Inertia::render('Attendance/Requests/Show', [
            'request' => AttendancePresenter::attendanceRequest($attendanceRequest),
            'preview' => in_array($attendanceRequest->status, [ApprovalStatus::Draft, ApprovalStatus::Pending], true)
                ? AttendanceRequestApproval::presentPlan($planner->plan($attendanceRequest)) : [],
            'timeline' => $approval === null ? null : $approvals->timeline($approval),
            'approval_request_id' => $approval?->id,
            'can' => [
                'update' => $user->can('update', $attendanceRequest),
                'withdraw' => $user->can('withdraw', $attendanceRequest),
                'cancel' => $attendanceRequest->status === ApprovalStatus::Approved && $user->can('cancelApproved', $attendanceRequest),
            ],
        ]);
    }

    public function update(AttendanceRequestRequest $request, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->authorize('update', $attendanceRequest);
        $employee = Employee::query()->findOrFail($attendanceRequest->employee_id);
        $this->actions->saveDraft($attendanceRequest, $employee, $request->validated());
        if ($request->boolean('submit')) {
            $this->actions->submit($attendanceRequest, $request->user(), (array) $request->validated('choices', []));
        }

        return $this->done($request->boolean('submit') ? 'attendance.messages.submitted' : 'common.saved');
    }

    public function destroy(AttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->authorize('update', $attendanceRequest);
        $attendanceRequest->delete();

        return $this->done('common.deleted', [], '/attendance-requests');
    }

    public function submit(Request $request, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->authorize('update', $attendanceRequest);
        $this->actions->submit($attendanceRequest, $request->user(), (array) $request->input('choices', []));

        return $this->done('attendance.messages.submitted');
    }

    public function withdraw(ReasonRequest $request, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->authorize('withdraw', $attendanceRequest);
        $this->actions->withdraw($attendanceRequest, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function cancel(ReasonRequest $request, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        $this->authorize('cancelApproved', $attendanceRequest);
        $this->actions->cancelApproved($attendanceRequest, $request->user(), (string) $request->validated('reason'),
            $request->user()->can('attendance.request.create-for-others'));

        return $this->done('common.cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    private function options(bool $forOthers): array
    {
        return [
            'kinds' => AttendanceRequestKind::options(),
            'sessions' => HalfDaySession::options(),
            'shift_types' => ShiftType::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray(),
            'employees' => $forOthers ? Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])->toArray() : [],
        ];
    }

    private function targetEmployee(AttendanceRequestRequest $request): Employee
    {
        $id = $request->validated('employee_id');
        $employee = $id === null
            ? Employee::query()->where('user_id', $request->user()->id)->first()
            : Employee::query()->find($id);
        abort_if($employee === null, 403);
        $this->authorize('createFor', [AttendanceRequest::class, $employee]);

        return $employee;
    }
}
