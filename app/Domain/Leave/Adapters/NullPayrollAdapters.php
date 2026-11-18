<?php

declare(strict_types=1);

namespace App\Domain\Leave\Adapters;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\AdditionalPayPort;
use App\Domain\Leave\Contracts\CompensationQuery;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * M2 bindings for the Payroll-implemented Leave ports (spec-02 §5.2–§5.3).
 * Payroll (M4) replaces them; encashments stay `awaiting_payroll` meanwhile and
 * are back-filled by M4's BackfillPendingEncashments.
 */
final class NullPayrollAdapters implements AdditionalPayPort, CompensationQuery
{
    public function createForEncashment(LeaveEncashment $encashment): ?int
    {
        return null;
    }

    public function cancelForEncashment(LeaveEncashment $encashment): void {}

    public function monthlyBasic(Employee $employee, CarbonImmutable $date): ?Money
    {
        return null;
    }
}
