<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Actions\AttendanceRecordActions;
use App\Domain\Attendance\Actions\MarkAttendanceManually;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Enums\ReviewReason;
use App\Domain\Attendance\Http\AttendancePresenter;
use App\Domain\Attendance\Http\Requests\MarkAttendanceRequest;
use App\Domain\Attendance\Http\Requests\ReasonRequest;
use App\Domain\Attendance\Http\Requests\ReviewActionRequest;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Policies\AttendancePolicy;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Employee;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR screens (spec-03 §2.10–§2.13): Rekod Kehadiran, Tanda Kehadiran Pukal,
 * Semakan Kehadiran (review queue) and Log Check-in, plus "Proses Sekarang".
 */
final class AttendanceRecordController extends Controller
{
    public function __construct(private readonly AttendanceRecordActions $actions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Attendance::class);
        $q = Attendance::query()->with(['employee', 'shiftType'])
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('attendance_status'), fn ($q, $v) => $q->where('attendance_status', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->where('status', (string) $request->query('status', 'submitted'))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('attendance_date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('attendance_date', '<=', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)))
            ->orderByDesc('attendance_date')->orderBy('employee_id');

        return Inertia::render('Attendance/Records/Index', [
            'records' => $q->paginate(50)->withQueryString()->through(fn (Attendance $a) => AttendancePresenter::attendance($a)),
            'filters' => $request->only(['employee_id', 'attendance_status', 'source', 'status', 'from', 'to', 'department_id']),
            'options' => $this->options(),
            'can' => ['manage' => $request->user()->can('attendance.manage')],
        ]);
    }

    public function show(Request $request, Attendance $attendance): Response
    {
        $this->authorize('view', $attendance);
        $chain = [];
        $cursor = $attendance;
        while ($cursor->supersedes_id !== null && count($chain) < 20) {
            $cursor = Attendance::query()->find($cursor->supersedes_id);
            if ($cursor === null) {
                break;
            }
            $chain[] = AttendancePresenter::attendance($cursor);
        }
        $showCoordinates = app(AttendancePolicy::class)->viewCoordinates($request->user(), $attendance->employee_id);

        return Inertia::render('Attendance/Records/Show', [
            'record' => AttendancePresenter::attendance($attendance->load(['employee', 'shiftType'])),
            'history' => $chain,
            'superseded_by' => $attendance->superseded_by_id,
            'checkins' => EmployeeCheckin::query()->where('attendance_id', $attendance->id)->with('location')->orderBy('punched_at')->get()
                ->map(fn (EmployeeCheckin $c) => AttendancePresenter::checkin($c, $showCoordinates))->values()->all(),
            'can' => ['manage' => $request->user()->can('attendance.manage')],
        ]);
    }

    public function cancel(ReasonRequest $request, Attendance $attendance): RedirectResponse
    {
        $this->requirePermission('attendance.manage');
        $this->actions->cancel($attendance, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    /** "Proses Semula Tarikh Ini". */
    public function reprocessDate(Request $request): RedirectResponse
    {
        $this->requirePermission('attendance.manage');
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'date' => ['required', 'date']]);
        $this->actions->reprocessDate(Employee::query()->findOrFail($data['employee_id']), CarbonImmutable::parse($data['date']), $request->user());

        return $this->done('attendance.messages.reprocessed');
    }

    /** Tanda Kehadiran Pukal page: current state per employee and date. */
    public function bulkMarkPage(Request $request): Response
    {
        $this->requirePermission('attendance.manage');
        $date = CarbonImmutable::parse((string) $request->query('date', CarbonImmutable::today()->toDateString()));
        $employees = Employee::query()->notLeft()
            ->when($request->query('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->orderBy('full_name')->get();
        $rows = Attendance::query()->live()->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('attendance_date', $date->toDateString())->get()->keyBy('employee_id');
        $review = EmployeeCheckin::query()->notVoided()->whereIn('employee_id', $employees->pluck('id'))
            ->where('processing_state', ProcessingState::NeedsReview->value)->whereDate('shift_date', $date->toDateString())
            ->pluck('employee_id')->flip();

        return Inertia::render('Attendance/BulkMark', [
            'date' => $date->toDateString(),
            'rows' => $employees->map(function (Employee $e) use ($rows, $review): array {
                /** @var Attendance|null $row */
                $row = $rows->get($e->id);

                return [
                    'employee' => AttendancePresenter::employee($e),
                    'state' => match (true) {
                        $row === null && $review->has($e->id) => 'needs_review',
                        $row === null => 'unmarked',
                        $row->isLeave() => 'on_leave_locked',
                        $row->attendance_status === AttendanceStatus::HalfDay && $row->other_half_status?->value === 'pending' => 'half_day_pending',
                        default => $row->attendance_status->value,
                    },
                    'read_only' => $row !== null && $row->isLeave(),
                    'attendance' => $row === null ? null : AttendancePresenter::attendance($row),
                ];
            })->values()->all(),
            'filters' => $request->only(['date', 'branch_id', 'department_id']),
            'options' => $this->options() + [
                'statuses' => array_values(array_filter(AttendanceStatus::options(), fn ($o) => $o['value'] !== 'on_leave')),
                'shift_types' => ShiftType::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray(),
            ],
            'max_days' => MarkAttendanceManually::MAX_DAYS,
        ]);
    }

    public function bulkMark(MarkAttendanceRequest $request, MarkAttendanceManually $mark): RedirectResponse|JsonResponse
    {
        $this->requirePermission('attendance.manage');
        $data = $request->validated();
        $from = CarbonImmutable::parse($data['from_date']);
        $to = CarbonImmutable::parse($data['to_date'] ?? $data['from_date']);
        $summary = $mark->bulk(array_map('intval', $data['employee_ids']), $from, $to, $data, $request->user());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($summary);
        }

        return $this->done(isset($summary['queued']) ? 'attendance.messages.bulk_queued' : 'attendance.messages.bulk_done')->with('bulk_summary', $summary);
    }

    /** Result of a queued bulk marking (JSON; null while running). */
    public function bulkResult(string $token): JsonResponse
    {
        $this->requirePermission('attendance.manage');

        return response()->json(['result' => MarkAttendanceManually::queuedResult($token)]);
    }

    /** Semakan Kehadiran: punches in review grouped by reason (oldest first), plus flagged records. */
    public function review(Request $request): Response
    {
        $this->requirePermission('attendance.review.manage');
        $showCoordinates = $request->user()->can('attendance.location.view-coordinates');
        $punches = EmployeeCheckin::query()->notVoided()->with(['employee', 'location'])
            ->where('processing_state', ProcessingState::NeedsReview->value)
            ->when($request->query('reason'), fn ($q, $v) => $q->where('review_reason', $v))
            ->orderBy('punched_at')->limit(500)->get();

        $groups = [];
        foreach ($punches as $punch) {
            $key = ($punch->review_reason->value ?? 'unknown').'|'.$punch->employee_id.'|'.($punch->shift_date?->toDateString() ?? $punch->punched_at->toDateString());
            $groups[$key] ??= [
                'reason' => $punch->review_reason?->value,
                'reason_label' => $punch->review_reason?->label(),
                'employee' => AttendancePresenter::employee($punch->employee),
                'date' => $punch->shift_date?->toDateString() ?? $punch->punched_at->toDateString(),
                'checkins' => [],
            ];
            $groups[$key]['checkins'][] = AttendancePresenter::checkin($punch, $showCoordinates);
        }

        return Inertia::render('Attendance/Review', [
            'groups' => array_values($groups),
            'counts' => EmployeeCheckin::query()->notVoided()->where('processing_state', ProcessingState::NeedsReview->value)
                ->selectRaw('review_reason, COUNT(*) as total')->groupBy('review_reason')->pluck('total', 'review_reason')->all(),
            'flagged_records' => Attendance::query()->live()->whereNotNull('review_flag')->with('employee')
                ->orderBy('attendance_date')->limit(200)->get()->map(fn (Attendance $a) => AttendancePresenter::attendance($a))->values()->all(),
            'filters' => $request->only(['reason']),
            'options' => ['reasons' => ReviewReason::options()],
        ]);
    }

    public function reviewAction(ReviewActionRequest $request): RedirectResponse
    {
        $this->requirePermission('attendance.review.manage');
        $data = $request->validated();
        $ids = array_map('intval', $data['checkin_ids']);
        match ($data['action']) {
            'ignore' => $this->actions->ignore($ids, $request->user(), (string) ($data['note'] ?? '')),
            'reprocess' => $this->actions->reprocess($ids, $request->user()),
            'replace_existing' => $this->actions->replaceExisting($ids, $request->user()),
            default => abort(422),
        };

        return $this->done('common.saved');
    }

    /** Log Check-in — coordinates column only with view-coordinates. */
    public function checkins(Request $request): Response
    {
        $this->requirePermission('attendance.view-all');
        $showCoordinates = $request->user()->can('attendance.location.view-coordinates');
        $q = EmployeeCheckin::query()->with(['employee', 'location'])
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('geofence_result'), fn ($q, $v) => $q->where('geofence_result', $v))
            ->when($request->query('processing_state'), fn ($q, $v) => $q->where('processing_state', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('punched_at', '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($request->query('to'), fn ($q, $v) => $q->where('punched_at', '<=', CarbonImmutable::parse($v)->endOfDay()))
            ->orderByDesc('punched_at');

        return Inertia::render('Attendance/Checkins', [
            'checkins' => $q->paginate(50)->withQueryString()->through(fn (EmployeeCheckin $c) => AttendancePresenter::checkin($c, $showCoordinates)),
            'filters' => $request->only(['employee_id', 'geofence_result', 'processing_state', 'from', 'to']),
            'options' => $this->options() + ['geofence_results' => GeofenceResult::options(), 'processing_states' => ProcessingState::options()],
            'can' => ['void' => $request->user()->can('attendance.checkin.void'), 'view_coordinates' => $showCoordinates],
        ]);
    }

    public function voidCheckin(ReasonRequest $request, EmployeeCheckin $checkin): RedirectResponse
    {
        $this->requirePermission('attendance.checkin.void');
        $this->actions->voidCheckin($checkin, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.saved');
    }

    /** "Proses Sekarang": cut-off processing + absent marking for this organisation. */
    public function processNow(): RedirectResponse
    {
        $this->requirePermission('attendance.manage');
        $stats = $this->actions->processNow();

        return $this->done('attendance.messages.processed')->with('process_stats', $stats);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])->toArray(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'attendance_statuses' => AttendanceStatus::options(),
        ];
    }
}
