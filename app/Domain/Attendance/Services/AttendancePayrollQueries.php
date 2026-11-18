<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Enums\HalfDayBasis;
use App\Domain\Attendance\Enums\OtherHalfStatus;
use App\Domain\Attendance\Enums\ProcessingState;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\EmployeeAttendanceProfile;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Support\AttendancePeriodSummary;
use App\Domain\Attendance\Support\EmployeeCompleteness;
use App\Domain\Attendance\Support\OvertimePayLine;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;

/**
 * Attendance → Payroll queries (spec-03 §5.2, spec-04 §4.2.1). Only dates inside
 * the employee's active range count. LWP never comes from here (Leave owns it).
 */
final class AttendancePayrollQueries implements AttendanceForPayroll
{
    public function __construct(
        private readonly DayTypeResolver $dayTypes,
        private readonly ShiftCalendar $calendar,
        private readonly LeaveQuery $leave,
    ) {}

    public function periodSummary(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): AttendancePeriodSummary
    {
        $start = $from->startOfDay()->max($employee->date_of_joining);
        $end = $to->startOfDay();
        if ($employee->relieving_date !== null) {
            $end = $end->min($employee->relieving_date);
        }

        $rows = $end->lessThan($start) ? collect() : Attendance::query()->live()->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('attendance_date')->get();

        $absent = [];
        $present = '0';
        $wfh = '0';
        $onDuty = '0';
        $onLeave = '0';
        $restWorked = [];
        $phWorked = [];
        $late = 0;
        $lateMinutes = 0;
        $early = 0;
        $earlyMinutes = 0;
        $byDate = [];

        foreach ($rows as $row) {
            $date = $row->attendance_date->toDateString();
            $byDate[$date] = true;
            $status = $row->attendance_status;

            if ($status === AttendanceStatus::Absent) {
                $absent[$date] = bcadd($absent[$date] ?? '0', $row->is_half_day_off ? '0.5' : '1', 3);
            } elseif ($status === AttendanceStatus::Present) {
                $present = bcadd($present, '1', 3);
            } elseif ($status === AttendanceStatus::Wfh) {
                $wfh = bcadd($wfh, '1', 3);
            } elseif ($status === AttendanceStatus::OnDuty) {
                $onDuty = bcadd($onDuty, '1', 3);
            } elseif ($status === AttendanceStatus::OnLeave) {
                $onLeave = bcadd($onLeave, '1', 3);
            } elseif ($status === AttendanceStatus::HalfDay) {
                if ($row->half_day_basis === HalfDayBasis::Worked) {
                    $present = bcadd($present, '0.5', 3);
                } elseif ($row->half_day_basis === HalfDayBasis::Leave) {
                    $onLeave = bcadd($onLeave, '0.5', 3);
                } elseif ($this->requestKind($row) === 'on_duty') {
                    $onDuty = bcadd($onDuty, '0.5', 3);
                } else {
                    $wfh = bcadd($wfh, '0.5', 3);
                }

                if ($row->other_half_status === OtherHalfStatus::Present) {
                    $present = bcadd($present, '0.5', 3);
                } elseif ($row->other_half_status === OtherHalfStatus::Wfh) {
                    $wfh = bcadd($wfh, '0.5', 3);
                } elseif ($row->other_half_status === OtherHalfStatus::OnDuty) {
                    $onDuty = bcadd($onDuty, '0.5', 3);
                } elseif ($row->other_half_status === OtherHalfStatus::Absent) {
                    // The non-covered half is absent whatever the basis; the leave half itself is Leave's (LWP) business.
                    $absent[$date] = bcadd($absent[$date] ?? '0', '0.5', 3);
                }
            }

            if ($row->isWorked() && $row->day_type === DayType::RestDay) {
                $restWorked[] = $date;
            }
            if ($row->isWorked() && $row->day_type === DayType::PublicHoliday) {
                $phWorked[] = $date;
            }
            if ($row->is_late) {
                $late++;
                $lateMinutes += $row->late_minutes;
            }
            if ($row->is_early_exit) {
                $early++;
                $earlyMinutes += $row->early_exit_minutes;
            }
        }

        $needsReview = $end->lessThan($start) ? [] : EmployeeCheckin::query()->notVoided()->where('employee_id', $employee->id)
            ->where('processing_state', ProcessingState::NeedsReview->value)
            ->where(fn ($q) => $q->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
                ->orWhere(fn ($q) => $q->whereNull('shift_date')->whereBetween('punched_at', [$start->startOfDay(), $end->endOfDay()])))
            ->get()->map(fn (EmployeeCheckin $c) => ($c->shift_date ?? $c->punched_at)->toDateString())
            ->unique()->sort()->values()->all();

        $unmarked = [];
        if (! $end->lessThan($start) && EmployeeAttendanceProfile::for($employee)->checkin_required) {
            foreach ((new DateRange($start, $end))->dates() as $d) {
                $key = $d->toDateString();
                if (isset($byDate[$key]) || in_array($key, $needsReview, true)) {
                    continue;
                }
                $shift = $this->calendar->occurrenceOn($employee, $d)?->shift;
                if (! $this->dayTypes->for($employee, $d, $shift)->isNormal()) {
                    continue;
                }
                if ($this->leave->approvedLeaveOn($employee, $d) !== null) {
                    continue;
                }
                $unmarked[] = $key;
            }
        }

        ksort($absent);
        $absentDates = [];
        $absentTotal = '0';
        foreach ($absent as $date => $fraction) {
            $absentDates[] = ['date' => $date, 'fraction' => $fraction];
            $absentTotal = bcadd($absentTotal, $fraction, 3);
        }

        return new AttendancePeriodSummary(
            $absentTotal, $absentDates, count($unmarked), $unmarked, $needsReview,
            $present, $wfh, $onDuty, $onLeave, $restWorked, $phWorked, $late, $lateMinutes, $early, $earlyMinutes,
        );
    }

    public function completeness(int $organisationId, CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds = null): array
    {
        return OrganisationContext::run($organisationId, function () use ($from, $to, $employeeIds): array {
            $employees = Employee::query()
                ->whereDate('date_of_joining', '<=', $to->toDateString())
                ->where(fn ($q) => $q->whereNull('relieving_date')->orWhereDate('relieving_date', '>=', $from->toDateString()))
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
                ->orderBy('full_name')->get();

            $out = [];
            foreach ($employees as $employee) {
                $summary = $this->periodSummary($employee, $from, $to);
                if ($summary->unmarkedDates !== [] || $summary->needsReviewDates !== []) {
                    $out[] = new EmployeeCompleteness($employee->id, $employee->full_name, $summary->unmarkedDates, $summary->needsReviewDates);
                }
            }

            return $out;
        });
    }

    public function overtimePayLines(Employee $employee, CarbonImmutable $periodMonth): array
    {
        $claims = OvertimeClaim::query()->where('employee_id', $employee->id)
            ->where('status', ApprovalStatus::Approved->value)
            ->whereNotNull('effects_applied_at')
            ->whereNull('posted_payroll_run_id')
            ->whereDate('period_month', '<=', $periodMonth->startOfMonth()->toDateString())
            ->with('lines')->orderBy('period_month')->get();

        $out = [];
        foreach ($claims as $claim) {
            $groups = [];
            foreach ($claim->lines as $line) {
                $key = (string) ($line->salary_component_id ?? 0);
                $groups[$key] ??= ['component' => $line->salary_component_id, 'minutes' => 0, 'amount' => '0', 'breakdown' => [], 'dates' => []];
                $paid = $line->day_type === DayType::Normal ? $line->beyond_normal_minutes : $line->worked_minutes;
                $groups[$key]['minutes'] += $paid;
                $groups[$key]['amount'] = bcadd($groups[$key]['amount'], (string) $line->amount, 2);
                $groups[$key]['breakdown'][$line->day_type->value] = ($groups[$key]['breakdown'][$line->day_type->value] ?? 0) + $paid;
                $groups[$key]['dates'][] = $line->work_date->toDateString();
            }
            foreach ($groups as $g) {
                $out[] = new OvertimePayLine(
                    $claim->id, $claim->number, $claim->period_month, $g['component'], $g['minutes'],
                    Money::of($g['amount']), $g['breakdown'], array_values(array_unique($g['dates'])),
                );
            }
        }

        return $out;
    }

    public function markOvertimePosted(array $claimIds, int $payrollRunId): void
    {
        foreach (OvertimeClaim::query()->whereIn('id', $claimIds)->whereNull('posted_payroll_run_id')->get() as $claim) {
            $claim->forceFill(['posted_payroll_run_id' => $payrollRunId])->save();
        }
    }

    public function releaseOvertime(int $payrollRunId): void
    {
        foreach (OvertimeClaim::query()->where('posted_payroll_run_id', $payrollRunId)->get() as $claim) {
            $claim->forceFill(['posted_payroll_run_id' => null])->save();
        }
    }

    private function requestKind(Attendance $row): string
    {
        $kind = $row->attendance_request_id === null ? null : AttendanceRequest::query()->whereKey($row->attendance_request_id)->value('kind');

        return (string) ($kind ?? 'wfh');
    }
}
