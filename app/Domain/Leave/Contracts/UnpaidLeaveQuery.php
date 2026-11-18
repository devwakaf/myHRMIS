<?php

declare(strict_types=1);

namespace App\Domain\Leave\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Support\UnpaidLeaveSummary;
use App\Domain\Shared\Dating\DateRange;

/**
 * Leave → Payroll (spec-02 §5.2 + spec-04 §4.2.1 delta 1). Implemented by Leave,
 * called by Payroll. Unpaid per day = portion × (1 − paid_fraction).
 */
interface UnpaidLeaveQuery
{
    /** Approved, non-cancelled leave days in range with unpaid_portion > 0. */
    public function unpaidDays(Employee $employee, DateRange $payPeriod): UnpaidLeaveSummary;

    /** Unpaid portion (3 dp) of applications still pending approval in the period. */
    public function pendingUnpaidDays(Employee $employee, DateRange $payPeriod): string;
}
