<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Adapters;

use App\Domain\Attendance\Contracts\OtWageBaseProvider;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;

/** M3 binding until Payroll (M4): wage unknown → HR enters a manual wage snapshot on the claim. */
final class NullOtWageBaseProvider implements OtWageBaseProvider
{
    public function monthlyWageFor(Employee $employee, CarbonImmutable $month): ?Money
    {
        return null;
    }
}
