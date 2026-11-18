<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Leave\Support\UnpaidLeaveSummary;
use App\Domain\Shared\Dating\DateRange;

/**
 * Test double for Leave → Payroll (spec-02 §5.2): configured unpaid days per
 * employee and date.
 */
final class FakeUnpaidLeaveQuery implements UnpaidLeaveQuery
{
    /** @var array<int, array<string, string>> employee id => [date => unpaid portion] */
    public array $days = [];

    /** @var array<int, string> employee id => pending unpaid days */
    public array $pending = [];

    public function unpaidDays(Employee $employee, DateRange $payPeriod): UnpaidLeaveSummary
    {
        $items = [];
        $total = '0';
        foreach ($this->days[$employee->id] ?? [] as $date => $portion) {
            if (! $payPeriod->contains($date)) {
                continue;
            }
            $items[] = ['date' => $date, 'leave_application_id' => 0, 'leave_type_id' => 0, 'portion' => $portion, 'unpaid_portion' => $portion];
            $total = bcadd($total, $portion, 3);
        }

        return new UnpaidLeaveSummary($total, $items);
    }

    public function pendingUnpaidDays(Employee $employee, DateRange $payPeriod): string
    {
        return $this->pending[$employee->id] ?? '0.000';
    }
}
