<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Actions\ShiftAssignmentActions;
use App\Domain\Attendance\Enums\AssignmentChangeCause;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\ShiftAssignmentSource;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Http\AttendancePresenter;
use App\Domain\Attendance\Http\Requests\ReasonRequest;
use App\Domain\Attendance\Http\Requests\RosterRequest;
use App\Domain\Attendance\Http\Requests\ShiftAssignmentRequest;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeRestDay;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftScheduleAssignment;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Notifications\RosterChanged;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\RosterGenerator;
use App\Domain\Attendance\Services\ShiftAssignmentWriter;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Dating\DateRange;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penugasan Shift (single + bulk wizard) and the Roster board (spec-03 §2.6, §2.9,
 * §4.9). All writes go through ShiftAssignmentWriter / RosterGenerator.
 */
final class ShiftPlanningController extends Controller
{
    public function __construct(
        private readonly ShiftAssignmentWriter $writer,
        private readonly ShiftAssignmentActions $assignments,
        private readonly RosterGenerator $rosters,
    ) {}

    public function assignments(Request $request): Response
    {
        $this->requirePermission('attendance.manage', 'attendance.view-all');
        $q = ShiftAssignment::query()->with(['employee', 'shiftType'])
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('shift_type_id'), fn ($q, $v) => $q->where('shift_type_id', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->query('status', 'submitted'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $v)))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('start_date', '<=', $v))
            ->when($request->query('branch_id'), fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $v)))
            ->when($request->query('department_id'), fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)))
            ->orderByDesc('start_date');

        return Inertia::render('Attendance/ShiftAssignments/Index', [
            'assignments' => $q->paginate(50)->withQueryString()->through(fn (ShiftAssignment $a) => AttendancePresenter::assignment($a)),
            'filters' => $request->only(['employee_id', 'shift_type_id', 'source', 'status', 'from', 'to', 'branch_id', 'department_id']),
            'options' => $this->options(),
            'can' => ['manage' => $request->user()->can('attendance.manage')],
        ]);
    }

    /** Single assignment, or bulk wizard (employee_ids + mode; `preview` returns JSON results without saving). */
    public function storeAssignment(ShiftAssignmentRequest $request): RedirectResponse|JsonResponse
    {
        $this->requirePermission('attendance.manage');
        $data = $request->validated();

        if (! empty($data['employee_ids'])) {
            $preview = $request->boolean('preview');
            $results = $this->assignments->bulk(array_map('intval', $data['employee_ids']), $data + ['mode' => $data['mode'] ?? 'add'], ! $preview);
            if ($preview || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return response()->json(['results' => $results, 'committed' => ! $preview]);
            }

            return $this->done('common.saved')->with('bulk_results', $results);
        }

        abort_if(empty($data['employee_id']), 422);
        $this->assignments->create($data + ['employee_id' => (int) $data['employee_id']]);

        return $this->done('common.saved');
    }

    public function cancelAssignment(ReasonRequest $request, ShiftAssignment $assignment): RedirectResponse
    {
        $this->requirePermission('attendance.manage');
        $this->writer->cancel($assignment, $request->user(), (string) $request->validated('reason'));

        return $this->done('common.cancelled');
    }

    /** "Tamatkan": set the end date (not before the last processed date). */
    public function endAssignment(Request $request, ShiftAssignment $assignment): RedirectResponse
    {
        $this->requirePermission('attendance.manage');
        $data = $request->validate(['end_date' => ['required', 'date']]);
        $this->writer->end($assignment, CarbonImmutable::parse($data['end_date']));

        return $this->done('common.saved');
    }

    public function roster(Request $request, ShiftCalendar $calendar, DayTypeResolver $dayTypes): Response
    {
        $this->requirePermission('attendance.manage', 'attendance.view-all');
        $from = CarbonImmutable::parse((string) $request->query('from', CarbonImmutable::today()->startOfWeek()->toDateString()));
        $to = CarbonImmutable::parse((string) $request->query('to', $from->addDays(13)->toDateString()));
        if ($to->lessThan($from) || $from->diffInDays($to) > 62) {
            $to = $from->addDays(30);
        }

        $employees = Employee::query()->notLeft()
            ->when($request->query('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->query('shift_schedule_id'), fn ($q, $v) => $q->whereIn('id',
                ShiftScheduleAssignment::query()->where('shift_schedule_id', $v)->where('is_enabled', true)->pluck('employee_id')))
            ->orderBy('full_name')->limit(200)->get();

        $leaveDates = Attendance::query()->live()->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->where('attendance_status', AttendanceStatus::OnLeave->value)->orWhere('half_day_basis', 'leave'))
            ->get(['employee_id', 'attendance_date', 'attendance_status'])
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (Attendance $a) => [$a->attendance_date->toDateString() => $a->attendance_status->value])->all());

        $rows = [];
        foreach ($employees as $employee) {
            $cells = [];
            foreach ((new DateRange($from, $to))->dates() as $d) {
                $occurrence = $calendar->occurrenceOn($employee, $d);
                $info = $dayTypes->for($employee, $d, $occurrence?->shift);
                $cells[] = [
                    'date' => $d->toDateString(),
                    'shift' => $occurrence === null ? null : ['id' => $occurrence->shift->id, 'code' => $occurrence->shift->code, 'colour' => $occurrence->shift->colour, 'source' => $occurrence->assignment?->source->value ?? 'default', 'assignment_id' => $occurrence->assignment?->id],
                    'day_type' => $info->dayType->value,
                    'is_half_day_off' => $info->isHalfDayOff,
                    'holiday_label' => $info->label,
                    'leave' => $leaveDates[$employee->id][$d->toDateString()] ?? null,
                ];
            }
            $rows[] = ['employee' => AttendancePresenter::employee($employee), 'cells' => $cells];
        }

        return Inertia::render('Attendance/Roster', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'roster_assignments' => ShiftScheduleAssignment::query()->whereIn('employee_id', $employees->pluck('id'))->where('is_enabled', true)
                ->with('schedule')->get()->map(fn (ShiftScheduleAssignment $r) => [
                    'id' => $r->id, 'employee_id' => $r->employee_id, 'schedule' => $r->schedule->code,
                    'anchor_date' => $r->anchor_date->toDateString(), 'end_date' => $r->end_date?->toDateString(),
                    'generated_through' => $r->generated_through?->toDateString(),
                ])->values()->all(),
            'filters' => $request->only(['from', 'to', 'branch_id', 'department_id', 'shift_schedule_id']),
            'options' => $this->options(),
            'can' => ['manage' => $request->user()->can('attendance.manage')],
        ]);
    }

    public function rosterAction(RosterRequest $request): RedirectResponse
    {
        $this->requirePermission('attendance.manage');
        $data = $request->validated();
        $employeeIds = array_map('intval', $data['employee_ids'] ?? []);
        $from = isset($data['from_date']) ? CarbonImmutable::parse($data['from_date']) : null;
        $to = isset($data['to_date']) ? CarbonImmutable::parse($data['to_date']) : $from;
        $causeId = (int) (microtime(true) * 1000) % 2147483647;

        DB::transaction(function () use ($data, $employeeIds, $from, $to, $causeId): void {
            match ($data['action']) {
                'assign_pattern' => array_map(fn (int $id) => $this->rosters->assign([
                    'employee_id' => $id, 'shift_schedule_id' => (int) $data['shift_schedule_id'], 'anchor_date' => $data['anchor_date'],
                    'end_date' => $data['end_date'] ?? null, 'overtime_type_id' => $data['overtime_type_id'] ?? null,
                ]), $employeeIds),
                'set_shift', 'set_rest', 'clear' => $this->editCells($employeeIds, $from, $to, $data, $causeId),
                'swap' => $this->swap($employeeIds[0] ?? 0, (int) ($data['other_employee_id'] ?? 0), $from, $to, $causeId),
                'stop' => $this->rosters->stop(ShiftScheduleAssignment::query()->findOrFail($data['shift_schedule_assignment_id']), $from ?? CarbonImmutable::today()),
                default => abort(422),
            };
        });

        if ($from !== null) {
            $this->notifyChanges(array_merge($employeeIds, array_filter([(int) ($data['other_employee_id'] ?? 0)])), $from, $to ?? $from);
        }

        return $this->done('common.saved');
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  array<string, mixed>  $data
     */
    private function editCells(array $employeeIds, ?CarbonImmutable $from, ?CarbonImmutable $to, array $data, int $causeId): void
    {
        if ($from === null || $to === null) {
            throw new AttendanceRuleViolation('attendance.errors.range_invalid');
        }
        $target = match ($data['action']) {
            'set_shift' => ShiftType::query()->findOrFail($data['shift_type_id'] ?? 0),
            'set_rest' => 'rest',
            default => null,
        };
        foreach (Employee::query()->whereIn('id', $employeeIds)->get() as $employee) {
            $this->writer->replaceRange($employee, $from, $to, $target, AssignmentChangeCause::RosterEdit, $causeId, ShiftAssignmentSource::Manual);
        }
    }

    private function swap(int $a, int $b, ?CarbonImmutable $from, ?CarbonImmutable $to, int $causeId): void
    {
        if ($from === null || $to === null || $a === 0 || $b === 0) {
            throw new AttendanceRuleViolation('attendance.errors.range_invalid');
        }
        $calendar = app(ShiftCalendar::class);
        $ea = Employee::query()->findOrFail($a);
        $eb = Employee::query()->findOrFail($b);

        foreach ((new DateRange($from, $to))->dates() as $d) {
            $sa = $this->cellOf($calendar, $ea, $d);
            $sb = $this->cellOf($calendar, $eb, $d);
            $this->writer->replaceRange($ea, $d, $d, $sb, AssignmentChangeCause::RosterEdit, $causeId);
            $this->writer->replaceRange($eb, $d, $d, $sa, AssignmentChangeCause::RosterEdit, $causeId);
        }
    }

    /**
     * @return ShiftType|'rest'|null
     */
    private function cellOf(ShiftCalendar $calendar, Employee $employee, CarbonImmutable $date): ShiftType|string|null
    {
        if (EmployeeRestDay::query()->where('employee_id', $employee->id)->whereDate('rest_date', $date->toDateString())->exists()) {
            return 'rest';
        }

        return $calendar->occurrenceOn($employee, $date)?->shift;
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function notifyChanges(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $window = AttendanceSettings::current()->notify_roster_changes_days;
        if ($window <= 0 || $from->greaterThan(CarbonImmutable::today()->addDays($window))) {
            return;
        }
        foreach (Employee::query()->whereIn('id', $employeeIds)->with('user')->get() as $employee) {
            $employee->user?->notify(new RosterChanged($from->format('d/m/Y'), $to->format('d/m/Y')));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'shift_types' => ShiftType::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'colour', 'start_time', 'end_time'])->toArray(),
            'schedules' => ShiftSchedule::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'cycle_length_days'])->toArray(),
            'employees' => Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'full_name', 'employee_no', 'branch_id', 'department_id'])->toArray(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'locations' => ShiftLocation::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'sources' => ShiftAssignmentSource::options(),
        ];
    }
}
