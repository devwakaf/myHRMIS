<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Reports;

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\EmployeeRestDay;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only data sources for the Laporan Kehadiran screens and exports (spec-03 §7).
 * Every builder returns the same envelope: `columns`, `rows` and optional extras
 * (`days` for matrices, `summary`, `rollup`, `details`, `truncated`). Coordinates
 * are never part of any row.
 */
final class AttendanceReports
{
    public const EMPLOYEE_LIMIT = 200;

    public const MAX_RANGE_DAYS = 31;

    public function __construct(
        private readonly ShiftCalendar $calendar,
        private readonly DayTypeResolver $dayTypes,
        private readonly AttendanceForPayroll $payroll,
    ) {}

    /**
     * Laporan Kehadiran Bulanan: employee × day matrix with codes and totals.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function monthly(array $f): array
    {
        $month = CarbonImmutable::parse(((string) ($f['month'] ?? today()->format('Y-m'))).'-01')->startOfMonth();
        $end = $month->endOfMonth();
        [$employees, $truncated] = $this->employees($f, $month, $end);
        $ids = $employees->pluck('id');

        $attendances = Attendance::query()->live()->whereIn('employee_id', $ids)
            ->whereBetween('attendance_date', [$month->toDateString(), $end->toDateString()])->get()
            ->groupBy('employee_id')->map(fn (Collection $rows) => $rows->keyBy(fn (Attendance $a) => $a->attendance_date->toDateString()));
        $review = EmployeeCheckin::query()->notVoided()->whereIn('employee_id', $ids)
            ->where('processing_state', ProcessingState::NeedsReview->value)
            ->whereBetween('shift_date', [$month->toDateString(), $end->toDateString()])->get(['employee_id', 'shift_date'])
            ->groupBy('employee_id')->map(fn (Collection $rows) => $rows->map(fn (EmployeeCheckin $c) => (string) $c->shift_date?->toDateString())->flip());
        $leaveCodes = LeaveType::query()->pluck('code', 'id');
        $today = CarbonImmutable::today();

        $rows = [];
        foreach ($employees as $employee) {
            $own = $attendances->get($employee->id, collect());
            $t = ['present' => 0.0, 'wfh' => 0.0, 'on_duty' => 0.0, 'absent' => 0.0, 'half_days' => 0, 'leave' => 0.0, 'public_holidays' => 0, 'rest_days' => 0,
                'unmarked' => 0, 'review' => 0, 'late_count' => 0, 'late_minutes' => 0, 'early_count' => 0, 'early_minutes' => 0, 'worked' => 0.0];
            $byLeave = [];
            $cells = [];

            for ($d = $month; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
                $ds = $d->toDateString();
                $cell = ['d' => (int) $d->format('j'), 'c' => '', 'f' => '', 't' => '', 'h' => ''];
                /** @var Attendance|null $a */
                $a = $own->get($ds);
                $employed = $d->greaterThanOrEqualTo($employee->date_of_joining) && ($employee->relieving_date === null || $d->lessThanOrEqualTo($employee->relieving_date));

                if ($a !== null) {
                    $cell['c'] = $this->code($a);
                    $cell['f'] = ($a->is_late ? 'L' : '').($a->is_early_exit ? 'A' : '');
                    $cell['t'] = $a->attendance_status !== AttendanceStatus::Absent && $a->attendance_status !== AttendanceStatus::OnLeave && $a->day_type !== DayType::Normal ? $a->day_type->value : '';
                    $this->tally($t, $byLeave, $a, $leaveCodes);
                    if ($a->review_flag !== null) {
                        $t['review']++;
                    }
                } elseif ($employed) {
                    $occurrence = $this->calendar->occurrenceOn($employee, $d);
                    $info = $this->dayTypes->for($employee, $d, $occurrence?->shift);
                    if ($info->dayType === DayType::PublicHoliday) {
                        $cell['c'] = 'CU';
                        $cell['h'] = (string) $info->label;
                        $t['public_holidays']++;
                    } elseif ($info->dayType === DayType::RestDay) {
                        $cell['c'] = 'R';
                        $t['rest_days']++;
                    } elseif ($review->get($employee->id)?->has($ds)) {
                        $cell['c'] = '?';
                        $t['review']++;
                    } elseif ($occurrence !== null && $d->lessThan($today)) {
                        $t['unmarked']++;
                    }
                }
                $cells[] = $cell;
            }

            $rows[] = [
                'employee_no' => $employee->employee_no,
                'employee' => $employee->full_name,
                'cells' => $cells,
                'present' => $this->dec($t['present']),
                'wfh' => $this->dec($t['wfh']),
                'on_duty' => $this->dec($t['on_duty']),
                'absent' => $this->dec($t['absent']),
                'half_days' => $t['half_days'],
                'leave_days' => $this->dec($t['leave']),
                'leave_by_type' => implode('; ', array_map(fn (string $code, float $n) => $code.' '.$this->dec($n), array_keys($byLeave), $byLeave)),
                'public_holidays' => $t['public_holidays'],
                'rest_days' => $t['rest_days'],
                'unmarked' => $t['unmarked'],
                'review' => $t['review'],
                'late_count' => $t['late_count'],
                'late_minutes' => $t['late_minutes'],
                'early_count' => $t['early_count'],
                'early_minutes' => $t['early_minutes'],
                'worked_hours' => $this->dec($t['worked']),
            ];
        }

        $days = [];
        for ($d = $month; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $days[] = ['day' => (int) $d->format('j'), 'date' => $d->toDateString(), 'weekday' => (int) $d->format('N')];
        }

        return [
            'kind' => 'matrix',
            'columns' => $this->columns(['employee_no', 'employee']),
            'total_columns' => $this->columns(['present', 'wfh', 'on_duty', 'absent', 'half_days', 'leave_days', 'leave_by_type', 'public_holidays', 'rest_days', 'unmarked', 'review', 'late_count', 'late_minutes', 'early_count', 'early_minutes', 'worked_hours']),
            'days' => $days,
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * Laporan Shift: per employee per day, with separate summary buckets.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function shift(array $f, int $limit): array
    {
        [$from, $to] = $this->range($f, today()->startOfMonth()->toImmutable(), today()->toImmutable());
        $noGrace = filter_var($f['no_grace'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = $this->attendanceQuery($f, $from, $to)->with(['employee', 'shiftType'])
            ->orderBy('attendance_date')->orderBy('employee_id');
        $records = $query->limit($limit + 1)->get();
        $truncated = $records->count() > $limit;

        $rows = $records->take($limit)->map(function (Attendance $a) use ($noGrace): array {
            $late = $a->late_minutes;
            $early = $a->early_exit_minutes;
            if ($noGrace && $a->in_at !== null && $a->scheduled_start_at !== null) {
                $late = max(0, (int) $a->scheduled_start_at->diffInMinutes($a->in_at, false));
            }
            if ($noGrace && $a->out_at !== null && $a->scheduled_end_at !== null) {
                $early = max(0, (int) $a->out_at->diffInMinutes($a->scheduled_end_at, false));
            }

            return [
                'date' => $a->attendance_date->toDateString(),
                'employee_no' => $a->employee?->employee_no,
                'employee' => $a->employee?->full_name,
                'shift' => $a->shiftType?->code,
                'scheduled_in' => $a->scheduled_start_at?->format('H:i'),
                'scheduled_out' => $a->scheduled_end_at?->format('H:i'),
                'actual_in' => $a->in_at?->format('H:i'),
                'actual_out' => $a->out_at?->format('H:i'),
                'worked_hours' => $a->worked_hours === null ? null : (string) $a->worked_hours,
                'late_minutes' => $late,
                'early_minutes' => $early,
                'day_type' => $a->day_type->label(),
                'status' => $a->attendance_status->label(),
                'source' => $a->source->label(),
            ];
        })->values()->all();

        $employees = $this->employees($f, $from, $to)[0];
        $bucket = ['present' => 0.0, 'remote' => 0.0, 'leave' => 0.0, 'absent' => 0.0, 'unmarked' => 0.0, 'review' => 0.0];
        foreach ($employees as $employee) {
            $s = $this->payroll->periodSummary($employee, $from, $to);
            $bucket['present'] += (float) $s->presentDays;
            $bucket['remote'] += (float) $s->wfhDays + (float) $s->onDutyDays;
            $bucket['leave'] += (float) $s->onLeaveDays;
            $bucket['absent'] += (float) $s->absentDays;
            $bucket['unmarked'] += $s->unmarkedDays;
            $bucket['review'] += count($s->needsReviewDates);
        }

        return [
            'kind' => 'table',
            'columns' => $this->columns(['date', 'employee_no', 'employee', 'shift', 'scheduled_in', 'scheduled_out', 'actual_in', 'actual_out', 'worked_hours', 'late_minutes', 'early_minutes', 'day_type', 'status', 'source']),
            'rows' => $rows,
            'summary' => array_map(fn (float $v) => $this->dec($v), $bucket),
            'truncated' => $truncated,
        ];
    }

    /**
     * Laporan Lewat Masuk / Keluar Awal: counts and minutes per employee, department roll-up, day drill-down.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function late(array $f): array
    {
        [$from, $to] = $this->range($f, today()->startOfMonth()->toImmutable(), today()->toImmutable());
        $min = max(1, (int) ($f['min_count'] ?? 1));

        $totals = $this->attendanceQuery($f, $from, $to)
            ->selectRaw('employee_id, SUM(is_late) as late_count, SUM(late_minutes) as late_minutes, SUM(is_early_exit) as early_count, SUM(early_exit_minutes) as early_minutes')
            ->groupBy('employee_id')->havingRaw('(SUM(is_late) + SUM(is_early_exit)) >= ?', [$min])->get();
        $employees = Employee::query()->with('department')->whereIn('id', $totals->pluck('employee_id'))->get()->keyBy('id');

        $rows = $totals->map(function (Attendance $r) use ($employees): array {
            $e = $employees->get($r->employee_id);

            return [
                'employee_id' => $r->employee_id,
                'employee_no' => $e?->employee_no,
                'employee' => $e?->full_name,
                'department' => $e?->department?->name,
                'late_count' => (int) $r->getAttribute('late_count'),
                'late_minutes' => (int) $r->getAttribute('late_minutes'),
                'early_count' => (int) $r->getAttribute('early_count'),
                'early_minutes' => (int) $r->getAttribute('early_minutes'),
            ];
        })->sortBy([['late_count', 'desc'], ['employee', 'asc']])->values();

        $rollup = $rows->groupBy(fn (array $r) => (string) ($r['department'] ?? ''))->map(fn (Collection $g, string $dept) => [
            'department' => $dept,
            'employees' => $g->count(),
            'late_count' => $g->sum('late_count'),
            'late_minutes' => $g->sum('late_minutes'),
            'early_count' => $g->sum('early_count'),
            'early_minutes' => $g->sum('early_minutes'),
        ])->sortBy('department')->values()->all();

        $details = [];
        if (! empty($f['employee_id'])) {
            $details = Attendance::query()->live()->where('employee_id', (int) $f['employee_id'])
                ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
                ->where(fn ($q) => $q->where('is_late', true)->orWhere('is_early_exit', true))
                ->with('shiftType')->orderBy('attendance_date')->get()->map(fn (Attendance $a) => [
                    'date' => $a->attendance_date->toDateString(),
                    'shift' => $a->shiftType?->code,
                    'scheduled_in' => $a->scheduled_start_at?->format('H:i'),
                    'actual_in' => $a->in_at?->format('H:i'),
                    'late_minutes' => $a->late_minutes,
                    'scheduled_out' => $a->scheduled_end_at?->format('H:i'),
                    'actual_out' => $a->out_at?->format('H:i'),
                    'early_minutes' => $a->early_exit_minutes,
                ])->values()->all();
        }

        return [
            'kind' => 'table',
            'columns' => $this->columns(['employee_no', 'employee', 'department', 'late_count', 'late_minutes', 'early_count', 'early_minutes']),
            'rows' => $rows->all(),
            'rollup' => $rollup,
            'rollup_columns' => $this->columns(['department', 'employees', 'late_count', 'late_minutes', 'early_count', 'early_minutes']),
            'details' => $details,
            'detail_columns' => $this->columns(['date', 'shift', 'scheduled_in', 'actual_in', 'late_minutes', 'scheduled_out', 'actual_out', 'early_minutes']),
        ];
    }

    /**
     * Laporan OT: per claim, minutes by day type, cap usage and flagged lines. Amounts only with $showPay.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function overtime(array $f, bool $showPay): array
    {
        $month = CarbonImmutable::parse(((string) ($f['month'] ?? today()->format('Y-m'))).'-01')->startOfMonth();
        $claims = OvertimeClaim::query()->with(['employee', 'lines'])->whereDate('period_month', $month->toDateString())
            ->when($f['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('employee_id')->get();

        $rows = $claims->map(function (OvertimeClaim $c) use ($showPay): array {
            $by = ['normal' => 0, 'rest_day' => 0, 'public_holiday' => 0];
            $flagged = 0;
            $flags = [];
            foreach ($c->lines as $line) {
                $by[$line->day_type->value] += $line->day_type === DayType::Normal ? $line->beyond_normal_minutes : $line->worked_minutes;
                if (! empty($line->flags)) {
                    $flagged++;
                    $flags = array_merge($flags, (array) $line->flags);
                }
            }

            return [
                'number' => $c->number,
                'employee_no' => $c->employee?->employee_no,
                'employee' => $c->employee?->full_name,
                'status' => $c->status->label(),
                'eligibility' => $c->eligibility->label(),
                'normal_minutes' => $by['normal'],
                'rest_minutes' => $by['rest_day'],
                'holiday_minutes' => $by['public_holiday'],
                'total_hours' => $c->totalHours(),
                'cap_percent' => $c->cap_minutes ? number_format($c->cap_counted_minutes / $c->cap_minutes * 100, 1, '.', '') : null,
                'cap_exceeded' => $c->cap_exceeded ? (string) __('common.yes') : (string) __('common.no'),
                'flagged_lines' => $flagged,
                'flags' => implode(', ', array_map(fn (string $x) => (string) __('attendance.reports.flags.'.$x), array_values(array_unique($flags)))),
                'total_amount' => $showPay ? (string) $c->total_amount : null,
            ];
        })->values()->all();

        $keys = ['number', 'employee_no', 'employee', 'status', 'eligibility', 'normal_minutes', 'rest_minutes', 'holiday_minutes', 'total_hours', 'cap_percent', 'cap_exceeded', 'flagged_lines', 'flags'];
        if ($showPay) {
            $keys[] = 'total_amount';
        }

        return ['kind' => 'table', 'columns' => $this->columns($keys), 'rows' => $rows];
    }

    /**
     * Laporan Roster: printable shift codes per day (no coordinates).
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function roster(array $f): array
    {
        [$from, $to] = $this->range($f, today()->startOfWeek()->toImmutable(), today()->startOfWeek()->addDays(13)->toImmutable());
        [$employees, $truncated] = $this->employees($f, $from, $to);

        $rows = [];
        foreach ($employees as $employee) {
            $rest = EmployeeRestDay::query()->where('employee_id', $employee->id)
                ->whereBetween('rest_date', [$from->toDateString(), $to->toDateString()])->pluck('rest_date')
                ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->flip();
            $cells = [];
            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                $occurrence = $this->calendar->occurrenceOn($employee, $d);
                $info = $this->dayTypes->for($employee, $d, $occurrence?->shift);
                // An assigned shift wins; otherwise rest days and public holidays beat the default shift.
                $code = match (true) {
                    $occurrence?->assignment !== null => $occurrence->shift->code,
                    $info->dayType === DayType::PublicHoliday => 'CU',
                    $rest->has($d->toDateString()) || $info->dayType === DayType::RestDay => 'R',
                    $occurrence !== null => $occurrence->shift->code,
                    default => '',
                };
                $cells[] = ['d' => (int) $d->format('j'), 'c' => $code, 'f' => '', 't' => $code === 'CU' || $code === 'R' ? $info->dayType->value : '', 'h' => $occurrence?->shift->timeLabel() ?? (string) $info->label, 'colour' => $occurrence?->shift->colour];
            }
            $rows[] = ['employee_no' => $employee->employee_no, 'employee' => $employee->full_name, 'cells' => $cells];
        }

        $days = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $days[] = ['day' => (int) $d->format('j'), 'date' => $d->toDateString(), 'weekday' => (int) $d->format('N')];
        }

        return ['kind' => 'matrix', 'columns' => $this->columns(['employee_no', 'employee']), 'total_columns' => [], 'days' => $days, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Log Check-in export: geofence results, never the coordinates.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function checkins(array $f, int $limit): array
    {
        $from = CarbonImmutable::parse((string) ($f['from'] ?? today()->subDays(6)->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) ($f['to'] ?? today()->toDateString()))->endOfDay();
        $punches = EmployeeCheckin::query()->with(['employee', 'location'])
            ->whereBetween('punched_at', [$from, $to])
            ->when($f['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)))
            ->when($f['geofence_result'] ?? null, fn ($q, $v) => $q->where('geofence_result', $v))
            ->orderByDesc('punched_at')->limit($limit + 1)->get();
        $truncated = $punches->count() > $limit;

        $rows = $punches->take($limit)->map(fn (EmployeeCheckin $c) => [
            'punched_at' => $c->punched_at->format('Y-m-d H:i:s'),
            'employee_no' => $c->employee?->employee_no,
            'employee' => $c->employee?->full_name,
            'log_type' => $c->log_type?->label(),
            'source' => $c->source->label(),
            'geofence_result' => $c->geofence_result->label(),
            'location' => $c->location?->name,
            'distance_m' => $c->distance_m === null ? null : (int) round((float) $c->distance_m),
            'accuracy_m' => $c->accuracy_m === null ? null : (int) round((float) $c->accuracy_m),
            'processing_state' => $c->processing_state->label(),
            'clock_skew_seconds' => $c->clock_skew_seconds,
            'voided' => $c->voided_at !== null ? (string) __('common.yes') : (string) __('common.no'),
        ])->values()->all();

        return [
            'kind' => 'table',
            'columns' => $this->columns(['punched_at', 'employee_no', 'employee', 'log_type', 'source', 'geofence_result', 'location', 'distance_m', 'accuracy_m', 'processing_state', 'clock_skew_seconds', 'voided']),
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<Attendance>
     */
    private function attendanceQuery(array $f, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Attendance::query()->live()
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->when($f['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)))
            ->when($f['branch_id'] ?? null, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $v)));
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{0: Collection<int, Employee>, 1: bool}
     */
    private function employees(array $f, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $employees = Employee::query()
            ->whereDate('date_of_joining', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('relieving_date')->orWhereDate('relieving_date', '>=', $from->toDateString()))
            ->when($f['employee_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($f['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->orderBy('full_name')->limit(self::EMPLOYEE_LIMIT + 1)->get();

        return [$employees->take(self::EMPLOYEE_LIMIT), $employees->count() > self::EMPLOYEE_LIMIT];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(array $f, CarbonImmutable $defaultFrom, CarbonImmutable $defaultTo): array
    {
        $from = CarbonImmutable::parse((string) ($f['from'] ?? $defaultFrom->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) ($f['to'] ?? $defaultTo->toDateString()))->startOfDay();
        if ($to->lessThan($from)) {
            $to = $from;
        }
        if ($from->diffInDays($to) > 92) {
            $to = $from->addDays(92);
        }

        return [$from, $to];
    }

    /** Matrix cell code (spec-03 §7.1). */
    private function code(Attendance $a): string
    {
        return match ($a->attendance_status) {
            AttendanceStatus::Present => 'H',
            AttendanceStatus::Absent => 'TH',
            AttendanceStatus::Wfh => 'BR',
            AttendanceStatus::OnDuty => 'TL',
            AttendanceStatus::OnLeave => 'C',
            AttendanceStatus::HalfDay => ($a->half_day_basis?->value === 'leave' ? '½C·' : '½K·').match ($a->other_half_status?->value) {
                'present' => 'H', 'absent' => 'TH', 'wfh' => 'BR', 'on_duty' => 'TL', default => '?',
            },
        };
    }

    /**
     * @param  array<string, float|int>  $t
     * @param  array<string, float>  $byLeave
     * @param  Collection<int|string, string>  $leaveCodes
     */
    private function tally(array &$t, array &$byLeave, Attendance $a, Collection $leaveCodes): void
    {
        $other = $a->other_half_status?->value;
        switch ($a->attendance_status) {
            case AttendanceStatus::Present:
                $t['present']++;
                break;
            case AttendanceStatus::Wfh:
                $t['wfh']++;
                break;
            case AttendanceStatus::OnDuty:
                $t['on_duty']++;
                break;
            case AttendanceStatus::Absent:
                $t['absent']++;
                break;
            case AttendanceStatus::OnLeave:
                $t['leave']++;
                $this->addLeave($byLeave, $a, $leaveCodes, 1.0);
                break;
            case AttendanceStatus::HalfDay:
                $t['half_days']++;
                if ($a->half_day_basis?->value === 'leave') {
                    $t['leave'] += 0.5;
                    $this->addLeave($byLeave, $a, $leaveCodes, 0.5);
                }
                if ($other === 'absent') {
                    $t['absent'] += 0.5;
                }
                break;
        }
        if ($a->is_late) {
            $t['late_count']++;
            $t['late_minutes'] += $a->late_minutes;
        }
        if ($a->is_early_exit) {
            $t['early_count']++;
            $t['early_minutes'] += $a->early_exit_minutes;
        }
        $t['worked'] += (float) ($a->worked_hours ?? 0);
    }

    /**
     * @param  array<string, float>  $byLeave
     * @param  Collection<int|string, string>  $leaveCodes
     */
    private function addLeave(array &$byLeave, Attendance $a, Collection $leaveCodes, float $days): void
    {
        $code = (string) ($leaveCodes->get($a->leave_type_id) ?? '?');
        $byLeave[$code] = ($byLeave[$code] ?? 0.0) + $days;
    }

    private function dec(float $value): string
    {
        return number_format($value, 1, '.', '');
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string}>
     */
    private function columns(array $keys): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => (string) __('attendance.reports.columns.'.$key)], $keys);
    }
}
