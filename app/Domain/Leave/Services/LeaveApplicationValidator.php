<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeEligibility;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Enums\AttachmentRule;
use App\Domain\Leave\Enums\DaySession;
use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Enums\NoticeEnforcement;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\DayBreakdown;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Validation order on submit (spec-02 §3.9, steps 1–13). Errors are keyed by
 * form field; warnings never block. Used by submit (throws) and by the live
 * preview (returns everything).
 */
final class LeaveApplicationValidator
{
    public function __construct(
        private readonly LeaveSufficiency $sufficiency,
        private readonly BlockListChecker $blockLists,
        private readonly EventCounter $events,
        private readonly HolidayResolver $holidays,
        private readonly LeaveAttendancePort $attendance,
        private readonly PayrollLockQuery $payrollLock,
    ) {}

    /**
     * @return array{errors: array<string, string>, warnings: list<string>, block_hits: list<array<string, mixed>>, sufficiency: array<string, mixed>|null}
     */
    public function validate(LeaveApplication $app, Employee $employee, LeaveType $type, DayBreakdown $breakdown, int $attachmentCount): array
    {
        $errors = [];
        $warnings = [];
        $today = CarbonImmutable::today();
        $from = $app->from_date;
        $to = $app->to_date;
        $deductible = $breakdown->deductible();

        // 1. employee, type, eligibility
        if ($employee->status === EmployeeStatus::Left || ! EmployeeEligibility::isActiveOn($employee, $from) || ! EmployeeEligibility::isActiveOn($employee, $to)) {
            $errors['employee_id'] = __('leave.errors.employee_not_active');
        }
        if (! $type->is_active) {
            $errors['leave_type_id'] = __('leave.errors.type_inactive');
        }
        $eligibility = LeaveEligibility::reason($employee, $type);
        if ($eligibility !== null) {
            $errors['leave_type_id'] = __($eligibility);
        }

        // 2. backdating (HR on behalf ignores it)
        $maxBackdate = $type->max_backdate_days ?? LeaveSettings::current($employee->organisation_id)->default_max_backdate_days;
        if (! $app->is_on_behalf && $from->lessThan($today->subDays($maxBackdate))) {
            $errors['from_date'] = __('leave.errors.backdate_limit', ['days' => $maxBackdate]);
        }

        // 3. minimum service
        if ($type->min_service_days_to_apply > 0) {
            $served = (int) $employee->serviceStart()->diffInDays($from);
            if ($served < $type->min_service_days_to_apply) {
                $errors['leave_type_id'] = __('leave.errors.min_service', ['days' => $type->min_service_days_to_apply]);
            }
        }

        // 4. notice
        if ($type->min_notice_days > 0) {
            $notice = $today->lessThanOrEqualTo($from) ? (int) $today->diffInDays($from) : -1;
            if ($notice < $type->min_notice_days) {
                $message = (string) __('leave.errors.min_notice', ['days' => $type->min_notice_days]);
                if ($type->notice_enforcement === NoticeEnforcement::Block) {
                    $errors['from_date'] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
        }

        // 5. type limits, events, event date
        if ($type->max_days_per_application !== null && Dec::cmp($breakdown->totalDays, (string) $type->max_days_per_application) > 0) {
            $errors['to_date'] = __('leave.errors.max_per_application', ['days' => Dec::short((string) $type->max_days_per_application)]);
        }
        if ($type->entitlement_basis === EntitlementBasis::PerEvent) {
            if ($type->per_event_max_days !== null && Dec::cmp($breakdown->totalDays, (string) $type->per_event_max_days) > 0) {
                $errors['to_date'] = __('leave.errors.max_per_event', ['days' => Dec::short((string) $type->per_event_max_days)]);
            }
            $sameEvent = $app->is_on_behalf && $app->event_date !== null
                && $this->events->sameEventExists($employee, $type->id, $app->event_date->toDateString(), $app->id);
            if (! $sameEvent && $type->max_events_per_service !== null
                && $this->events->count($employee, $type->id, null, $app->id) + 1 > $type->max_events_per_service) {
                $errors['leave_type_id'] = __('leave.errors.max_events_service', ['count' => $type->max_events_per_service]);
            }
            $period = LeavePeriod::containing($from);
            if (! $sameEvent && $type->max_events_per_period !== null && $period !== null
                && $this->events->count($employee, $type->id, $period, $app->id) + 1 > $type->max_events_per_period) {
                $errors['leave_type_id'] = __('leave.errors.max_events_period', ['count' => $type->max_events_per_period]);
            }
        }
        if ($type->requires_event_date && $app->event_date === null) {
            $errors['event_date'] = __('leave.errors.event_date_required');
        }

        // 6. allocation coverage
        $sufficiency = null;
        if ($type->hasBalance()) {
            $uncovered = $this->uncoveredDates($employee, $type, $deductible);
            if ($uncovered !== []) {
                $errors['from_date'] = __('leave.errors.no_allocation', ['dates' => implode(', ', $uncovered)]);
            }
        }

        // 7. sufficiency (+ aggregate caps)
        if (! isset($errors['from_date'])) {
            $sufficiency = $this->sufficiency->check($employee, $type, $deductible, $app->id);
            if (! $sufficiency['ok']) {
                $errors['leave_type_id'] = __((string) $sufficiency['error'], $sufficiency['params']);
            }
            foreach ($sufficiency['warnings'] as $w) {
                $warnings[] = (string) __($w['key'], $w['params']);
            }
        }

        // 8. overlap with other pending/approved applications
        $overlap = $this->overlaps($app, $employee, $breakdown);
        if ($overlap !== []) {
            $errors['from_date'] = __('leave.errors.overlap', ['dates' => implode(', ', $overlap)]);
        }

        // 9. max consecutive (chained across rest days and holidays)
        if ($type->max_consecutive_days !== null) {
            $chain = $this->chainTotal($app, $employee, $type, $breakdown->totalDays);
            if (Dec::cmp($chain, (string) $type->max_consecutive_days) > 0) {
                $errors['to_date'] = __('leave.errors.max_consecutive', ['days' => Dec::short((string) $type->max_consecutive_days), 'chain' => Dec::short($chain)]);
            }
        }

        // 10. block list
        $hits = $this->blockLists->hits($employee, $type->id, array_map(fn (array $d) => $d['date'], $deductible));
        if ($hits !== [] && ! $app->block_list_acknowledged) {
            $errors['block_list_acknowledged'] = __('leave.errors.block_list_ack', ['dates' => implode(', ', array_unique(array_map(fn ($h) => CarbonImmutable::parse($h['date'])->format('d/m/Y'), $hits)))]);
        }

        // 11. attendance conflicts
        $conflicts = $this->attendanceConflicts($employee, $breakdown);
        if ($conflicts !== []) {
            $errors['from_date'] = __('leave.errors.attendance_conflict', ['dates' => implode(', ', $conflicts)]);
        }

        // 12. payroll lock
        [$lockedUnpaid, $lockedPaid] = $this->lockedDates($employee, $breakdown);
        if ($lockedUnpaid !== []) {
            $errors['from_date'] = __('leave.errors.payroll_locked', ['dates' => implode(', ', $lockedUnpaid)]);
        } elseif ($lockedPaid !== []) {
            $warnings[] = (string) __('leave.warnings.payroll_locked_paid', ['dates' => implode(', ', $lockedPaid)]);
        }

        // 13. attachment and reason
        $needsAttachment = match ($type->requires_attachment) {
            AttachmentRule::Always => true,
            AttachmentRule::OverDays => $type->attachment_over_days !== null && Dec::cmp($breakdown->totalDays, (string) $type->attachment_over_days) > 0,
            AttachmentRule::Never => false,
        };
        if ($needsAttachment && $attachmentCount === 0) {
            $errors['attachments'] = __('leave.errors.attachment_required');
        }
        if ($type->requires_reason && trim((string) $app->reason) === '') {
            $errors['reason'] = __('leave.errors.reason_required');
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'block_hits' => $hits, 'sufficiency' => $sufficiency];
    }

    /**
     * @param  list<array{date: CarbonImmutable, portion: string}>  $deductible
     * @return list<string> d/m/Y
     */
    public function uncoveredDates(Employee $employee, LeaveType $type, array $deductible): array
    {
        $allocations = LeaveAllocation::query()->live()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->get();
        $out = [];
        foreach ($deductible as $day) {
            $covered = $allocations->contains(fn (LeaveAllocation $a) => $day['date']->betweenIncluded($a->from_date, $a->to_date));
            if (! $covered) {
                $out[] = $day['date']->format('d/m/Y');
            }
        }

        return $out;
    }

    /**
     * Dates also covered by another pending/approved application of the employee
     * (two half days of different sessions on the same date are allowed).
     *
     * @return list<string> d/m/Y
     */
    public function overlaps(LeaveApplication $app, Employee $employee, DayBreakdown $breakdown): array
    {
        $others = DB::table('leave_application_days as d')
            ->join('leave_applications as a', 'a.id', '=', 'd.leave_application_id')
            ->where('a.employee_id', $employee->id)
            ->whereIn('a.status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->when($app->exists, fn ($q) => $q->where('a.id', '!=', $app->id))
            ->whereBetween('d.date', [$app->from_date->toDateString(), $app->to_date->toDateString()])
            ->get(['d.date', 'd.session']);

        $mine = [];
        foreach ($breakdown->days as $day) {
            $mine[$day['date']->toDateString()] = $day['session'];
        }

        $out = [];
        foreach ($others as $other) {
            $date = substr((string) $other->date, 0, 10);
            $session = $mine[$date] ?? null;
            if ($session === null) {
                continue;
            }
            $otherSession = DaySession::from((string) $other->session);
            $bothHalves = $session !== DaySession::Full && $otherSession !== DaySession::Full && $session !== $otherSession;
            if (! $bothHalves) {
                $out[] = CarbonImmutable::parse($date)->format('d/m/Y');
            }
        }

        return array_values(array_unique($out));
    }

    /** Σ total_days of this request chained with touching same-type requests (gaps of rest days/holidays only). */
    public function chainTotal(LeaveApplication $app, Employee $employee, LeaveType $type, string $total): string
    {
        $others = LeaveApplication::query()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->when($app->exists, fn ($q) => $q->whereKeyNot($app->id))
            ->orderBy('from_date')->get();

        $lo = $app->from_date;
        $hi = $app->to_date;
        $sum = $total;
        $used = [];

        do {
            $changed = false;
            foreach ($others as $other) {
                if (isset($used[$other->id])) {
                    continue;
                }
                $adjacent = false;
                if ($other->to_date->lessThan($lo)) {
                    $adjacent = $this->onlyRestDays($employee, $other->to_date->addDay(), $lo->subDay());
                } elseif ($other->from_date->greaterThan($hi)) {
                    $adjacent = $this->onlyRestDays($employee, $hi->addDay(), $other->from_date->subDay());
                }
                if ($adjacent) {
                    $used[$other->id] = true;
                    $sum = Dec::add($sum, (string) $other->total_days);
                    $lo = $lo->min($other->from_date);
                    $hi = $hi->max($other->to_date);
                    $changed = true;
                }
            }
        } while ($changed);

        return $sum;
    }

    /**
     * @return list<string> d/m/Y
     */
    private function attendanceConflicts(Employee $employee, DayBreakdown $breakdown): array
    {
        $first = $breakdown->days[0]['date'];
        $last = $breakdown->days[array_key_last($breakdown->days)]['date'];
        $conflicts = $this->attendance->conflicts($employee, new DateRange($first, $last));
        if ($conflicts === []) {
            return [];
        }

        $byDate = [];
        foreach ($breakdown->days as $day) {
            $byDate[$day['date']->toDateString()] = $day;
        }

        $out = [];
        foreach ($conflicts as $conflict) {
            $day = $byDate[$conflict['date']] ?? null;
            if ($day === null || ! Dec::isPositive($day['portion'])) {
                continue;
            }
            $worked = DaySession::tryFrom($conflict['session']) ?? DaySession::Full;
            if ($day['session'] === DaySession::Full || $worked === DaySession::Full || $worked === $day['session']) {
                $out[] = $day['date']->format('d/m/Y');
            }
        }

        return $out;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [locked dates with unpaid portion, locked dates paid only]
     */
    public function lockedDates(Employee $employee, DayBreakdown $breakdown): array
    {
        $first = $breakdown->days[0]['date'];
        $last = $breakdown->days[array_key_last($breakdown->days)]['date'];
        $locked = array_map(fn (CarbonImmutable $d) => $d->toDateString(), $this->payrollLock->lockedDatesIn($employee, new DateRange($first, $last)));
        if ($locked === []) {
            return [[], []];
        }

        $unpaid = [];
        $paid = [];
        foreach ($breakdown->days as $day) {
            if (! in_array($day['date']->toDateString(), $locked, true) || ! Dec::isPositive($day['portion'])) {
                continue;
            }
            if (Dec::isPositive($day['unpaid_portion'])) {
                $unpaid[] = $day['date']->format('d/m/Y');
            } else {
                $paid[] = $day['date']->format('d/m/Y');
            }
        }

        return [$unpaid, $paid];
    }

    private function onlyRestDays(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if ($to->lessThan($from)) {
            return true; // directly adjacent
        }
        if ($from->diffInDays($to) > 31) {
            return false;
        }

        $rows = $this->holidays->holidaysBetween($employee, $from, $to);
        foreach ((new DateRange($from, $to))->dates() as $date) {
            $row = $rows->get($date->toDateString());
            if ($row === null || $row['isHalfDay']) {
                return false;
            }
        }

        return true;
    }
}
