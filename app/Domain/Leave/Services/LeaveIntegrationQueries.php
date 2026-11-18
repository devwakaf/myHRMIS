<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveQuery;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Leave\Support\Dec;
use App\Domain\Leave\Support\LeaveDay;
use App\Domain\Leave\Support\UnpaidLeaveSummary;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leave's implementations of the queries other modules call (spec-02 §5.1–§5.2,
 * spec-04 §4.2.1): unpaid days for Payroll and approved leave days for Attendance.
 * Both read leave_application_days, the single source of truth.
 */
final class LeaveIntegrationQueries implements LeaveQuery, UnpaidLeaveQuery
{
    public function unpaidDays(Employee $employee, DateRange $payPeriod): UnpaidLeaveSummary
    {
        $rows = $this->days($employee, $payPeriod, ApprovalStatus::Approved)
            ->where('d.unpaid_portion', '>', 0)
            ->orderBy('d.date')
            ->get(['d.date', 'd.leave_application_id', 'a.leave_type_id', 'd.portion', 'd.unpaid_portion']);

        $items = [];
        $total = '0';
        foreach ($rows as $row) {
            $total = Dec::add($total, (string) $row->unpaid_portion);
            $items[] = [
                'date' => substr((string) $row->date, 0, 10),
                'leave_application_id' => (int) $row->leave_application_id,
                'leave_type_id' => (int) $row->leave_type_id,
                'portion' => Dec::norm((string) $row->portion),
                'unpaid_portion' => Dec::norm((string) $row->unpaid_portion),
            ];
        }

        return new UnpaidLeaveSummary($total, $items);
    }

    public function pendingUnpaidDays(Employee $employee, DateRange $payPeriod): string
    {
        return Dec::norm((string) $this->days($employee, $payPeriod, ApprovalStatus::Pending)->sum('d.unpaid_portion'));
    }

    public function approvedLeaveOn(Employee $employee, CarbonInterface $date): ?LeaveDay
    {
        $row = $this->days($employee, new DateRange($date, $date), ApprovalStatus::Approved)
            ->where('d.portion', '>', 0)
            ->first(['d.date', 'd.leave_application_id', 'a.leave_type_id', 'd.session', 'd.portion', 'd.unpaid_portion']);

        if ($row === null) {
            return null;
        }

        return new LeaveDay(
            substr((string) $row->date, 0, 10),
            (int) $row->leave_application_id,
            (int) $row->leave_type_id,
            (string) $row->session,
            Dec::norm((string) $row->portion),
            Dec::norm((string) $row->unpaid_portion),
        );
    }

    private function days(Employee $employee, DateRange $range, ApprovalStatus $status): Builder
    {
        return DB::table('leave_application_days as d')
            ->join('leave_applications as a', 'a.id', '=', 'd.leave_application_id')
            ->where('a.organisation_id', $employee->organisation_id)
            ->where('a.employee_id', $employee->id)
            ->where('a.status', $status->value)
            ->whereBetween('d.date', [$range->from->toDateString(), ($range->to ?? $range->from)->toDateString()]);
    }
}
