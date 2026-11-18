<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Leave\Actions\AddLeaveAttachment;
use App\Domain\Leave\Actions\CancelLeaveApplication;
use App\Domain\Leave\Actions\SubmitLeaveApplication;
use App\Domain\Leave\Http\LeavePresenter;
use App\Domain\Leave\Http\Requests\LeaveApplicationRequest;
use App\Domain\Leave\Http\Requests\LeaveAttachmentRequest;
use App\Domain\Leave\Http\Requests\ReasonRequest;
use App\Domain\Leave\Models\CompensatoryLeaveRequest;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveApplicationAttachment;
use App\Domain\Leave\Models\LeaveBlockList;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Leave\Services\LeaveCalendar;
use App\Domain\Shared\Approval\ApprovalPresenter;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cuti Saya, Mohon Cuti, Butiran / Senarai Permohonan Cuti, attachments and
 * Kalendar Cuti (spec-02 §6.3). Thin: rules live in the Actions.
 */
final class LeaveApplicationController extends Controller
{
    public function __construct(
        private readonly SubmitLeaveApplication $submitter,
        private readonly CancelLeaveApplication $canceller,
        private readonly LeaveBalance $balance,
    ) {}

    public function me(Request $request): Response
    {
        $employee = $this->ownEmployee($request);
        $date = CarbonImmutable::parse((string) $request->query('date', today()->toDateString()));

        $balances = [];
        foreach (LeaveType::query()->where('is_active', true)->orderBy('sort_order')->get() as $type) {
            $balances[] = ['type' => LeavePresenter::type($type)] + $this->balance->on($employee, $type, $date);
        }

        return Inertia::render('Leave/Me', [
            'employee' => ['id' => $employee->id, 'name' => $employee->full_name, 'employee_no' => $employee->employee_no],
            'date' => $date->toDateString(),
            'balances' => $balances,
            'applications' => LeaveApplication::query()->with('leaveType')->where('employee_id', $employee->id)
                ->orderByDesc('from_date')->limit(100)->get()->map(fn ($a) => LeavePresenter::application($a))->all(),
            'compensatory_requests' => CompensatoryLeaveRequest::query()->where('employee_id', $employee->id)
                ->orderByDesc('work_from')->limit(50)->get()->map(fn ($r) => LeavePresenter::compensatory($r))->all(),
        ]);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveApplication::class);
        $filters = $request->only(['status', 'leave_type_id', 'employee_id', 'from', 'to']);

        $applications = LeaveApplication::query()->with(['employee', 'leaveType'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['leave_type_id'] ?? null, fn ($q, $v) => $q->where('leave_type_id', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('to_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('from_date', '<=', $v))
            ->orderByDesc('from_date')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (LeaveApplication $a) => LeavePresenter::application($a));

        return Inertia::render('Leave/Applications/Index', [
            'applications' => $applications,
            'filters' => $filters,
            'options' => $this->options(),
            'can' => ['create_for_others' => $request->user()->can('leave.application.create-for-others')],
        ]);
    }

    public function create(Request $request): Response
    {
        $forOthers = $request->user()->can('leave.application.create-for-others');

        return Inertia::render('Leave/Applications/Create', [
            'options' => $this->options() + [
                'employees' => $forOthers
                    ? Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])->toArray()
                    : [],
                'sessions' => [['value' => 'am', 'label' => __('leave.enums.day_session.am')], ['value' => 'pm', 'label' => __('leave.enums.day_session.pm')]],
            ],
            'own_employee_id' => Employee::query()->where('user_id', $request->user()->id)->value('id'),
            'can' => ['create_for_others' => $forOthers],
            'settings' => ['default_max_backdate_days' => LeaveSettings::current()->default_max_backdate_days],
        ]);
    }

    public function preview(LeaveApplicationRequest $request): JsonResponse
    {
        $employee = $this->targetEmployee($request);

        return response()->json($this->submitter->preview($employee, $request->validated(), $request->user(), null, count($request->file('attachments', []))));
    }

    public function store(LeaveApplicationRequest $request, AddLeaveAttachment $attach): RedirectResponse
    {
        $employee = $this->targetEmployee($request);
        $data = $request->validated();
        $application = $this->submitter->saveDraft(null, $employee, $data, $request->user());
        foreach ($request->file('attachments', []) as $file) {
            $attach->handle($application, $file, [], $request->user());
        }

        $warnings = $request->boolean('submit') ? $this->submitter->submit($application, $request->user(), (array) ($data['choices'] ?? [])) : [];

        return $this->done($request->boolean('submit') ? 'leave.messages.submitted' : 'common.saved', $warnings, '/leave/applications/'.$application->id);
    }

    public function show(Request $request, LeaveApplication $application, ApprovalPresenter $approvals): Response
    {
        $this->authorize('view', $application);
        $user = $request->user();
        $approvalRequest = $application->approvalRequest()->first();

        return Inertia::render('Leave/Applications/Show', [
            'application' => LeavePresenter::applicationDetail($application, $user->can('viewAttachments', $application)),
            'timeline' => $approvalRequest === null ? null : $approvals->timeline($approvalRequest),
            'approval_request_id' => $approvalRequest?->id,
            'can' => [
                'update' => $user->can('update', $application),
                'submit' => $user->can('submit', $application),
                'withdraw' => $user->can('withdraw', $application),
                'cancel' => $application->status === ApprovalStatus::Approved && $this->canceller->mayCancelApproved($application, $user),
                'add_attachment' => $user->can('addAttachment', $application),
            ],
        ]);
    }

    public function update(LeaveApplicationRequest $request, LeaveApplication $application): RedirectResponse
    {
        $this->authorize('update', $application);
        $data = $request->validated();
        $employee = Employee::query()->findOrFail($application->employee_id);
        $this->submitter->saveDraft($application, $employee, $data, $request->user());
        $warnings = $request->boolean('submit') ? $this->submitter->submit($application, $request->user(), (array) ($data['choices'] ?? [])) : [];

        return $this->done($request->boolean('submit') ? 'leave.messages.submitted' : 'common.saved', $warnings);
    }

    public function destroy(LeaveApplication $application): RedirectResponse
    {
        $this->authorize('delete', $application);
        $application->delete();

        return $this->done('common.deleted', [], '/leave/me');
    }

    public function submit(Request $request, LeaveApplication $application): RedirectResponse
    {
        $this->authorize('submit', $application);
        $warnings = $this->submitter->submit($application, $request->user(), (array) $request->input('choices', []));

        return $this->done('leave.messages.submitted', $warnings);
    }

    public function withdraw(ReasonRequest $request, LeaveApplication $application): RedirectResponse
    {
        $this->authorize('withdraw', $application);
        $this->canceller->withdraw($application, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function cancel(ReasonRequest $request, LeaveApplication $application): RedirectResponse
    {
        $this->authorize('view', $application);
        $this->canceller->cancelApproved($application, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    public function cancelCopy(ReasonRequest $request, LeaveApplication $application): RedirectResponse
    {
        $this->authorize('view', $application);
        if ($application->status === ApprovalStatus::Pending) {
            $this->authorize('withdraw', $application);
        }
        $copy = $this->canceller->cancelAndCopy($application, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancel_duplicated', [], '/leave/applications/'.$copy->id);
    }

    public function storeAttachment(LeaveAttachmentRequest $request, LeaveApplication $application, AddLeaveAttachment $attach): RedirectResponse
    {
        $this->authorize('addAttachment', $application);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $attach->handle($application, $file, $request->safe()->except('file'), $request->user());

        return $this->done('common.saved');
    }

    public function downloadAttachment(Request $request, LeaveApplicationAttachment $attachment): StreamedResponse
    {
        $application = LeaveApplication::query()->findOrFail($attachment->leave_application_id);
        $this->authorize('viewAttachments', $application);
        activity('leave')->performedOn($application)->causedBy($request->user())->event('attachment_download')
            ->withProperties(['attachment_id' => $attachment->id])->log('attachment_download');

        return Storage::disk((string) config('myhrmis.documents.disk', 'local'))->download($attachment->file_path, $attachment->original_name);
    }

    public function calendar(Request $request, LeaveCalendar $calendar, HolidayResolver $resolver): Response
    {
        $month = CarbonImmutable::parse((string) $request->query('month', today()->format('Y-m-01')))->startOfMonth();

        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        $holidays = $employee === null ? [] : $resolver->holidaysBetween($employee, $month, $month->endOfMonth())
            ->map(fn (array $h, string $date) => ['date' => $date, 'name' => $h['name'], 'kind' => $h['kind'], 'is_half_day' => $h['isHalfDay']])
            ->values()->all();
        $blockDates = LeaveBlockList::query()->where('is_active', true)->with(['dates' => fn ($q) => $q
            ->whereDate('date', '>=', $month->toDateString())->whereDate('date', '<=', $month->endOfMonth()->toDateString())])->get()
            ->flatMap(fn (LeaveBlockList $l) => $l->dates->map(fn ($d) => ['date' => $d->date->toDateString(), 'list' => $l->name, 'reason' => $d->reason]))
            ->values()->all();

        return Inertia::render('Leave/Calendar', [
            'month' => $month->format('Y-m'),
            'entries' => $calendar->entries($request->user(), OrganisationContext::id(), new DateRange($month, $month->endOfMonth())),
            'holidays' => $holidays,
            'block_dates' => $blockDates,
        ]);
    }

    /** JSON balances for an employee (Butiran Kakitangan "Cuti" tab, form side panel). */
    public function balances(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(
            $employee->user_id === $request->user()->id || $request->user()->can('leave.application.view-all'),
            403,
        );
        $date = CarbonImmutable::parse((string) $request->query('date', today()->toDateString()));
        $out = [];
        foreach (LeaveType::query()->where('is_active', true)->orderBy('sort_order')->get() as $type) {
            $out[] = ['type' => LeavePresenter::type($type)] + $this->balance->on($employee, $type, $date);
        }

        return response()->json(['date' => $date->toDateString(), 'balances' => $out]);
    }

    private function ownEmployee(Request $request): Employee
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        abort_if($employee === null, 403, (string) __('leave.errors.no_employee_record'));

        return $employee;
    }

    private function targetEmployee(LeaveApplicationRequest $request): Employee
    {
        $id = $request->validated('employee_id');
        $employee = $id === null ? $this->ownEmployee($request) : Employee::query()->findOrFail($id);
        $this->authorize('applyFor', [LeaveApplication::class, $employee]);

        return $employee;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'types' => LeaveType::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn ($t) => LeavePresenter::type($t))->all(),
            'statuses' => ApprovalStatus::options(),
        ];
    }
}
