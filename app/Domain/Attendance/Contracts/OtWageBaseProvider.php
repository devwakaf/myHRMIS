<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Payroll → Attendance (spec-03 §5.3, spec-04 §4.2): the Akta Kerja monthly wage
 * (Σ full-cycle components flagged `ot_wage_base`). Null when unknown — the claim
 * then needs a manual wage snapshot. M3 binds NullOtWageBaseProvider.
 */
interface OtWageBaseProvider
{
    public function monthlyWageFor(Employee $employee, CarbonImmutable $month): ?Money;
}
