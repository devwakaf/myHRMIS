<?php

declare(strict_types=1);

namespace App\Domain\Leave\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Monthly basic salary for encashment (spec-02 §3.15, spec-04 §4.2). Implemented
 * by Payroll (M4); the M2 Null binding returns null and HR types the amount.
 */
interface CompensationQuery
{
    public function monthlyBasic(Employee $employee, CarbonImmutable $date): ?Money;
}
