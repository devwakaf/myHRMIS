<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;

/** Binding until Payroll (M4): nothing is locked. */
final class NullPayrollLockQuery implements PayrollLockQuery
{
    public function isLocked(Employee|int $employee, CarbonImmutable $date): bool
    {
        return false;
    }

    public function lockedDatesIn(Employee|int $employee, DateRange $range): array
    {
        return [];
    }
}
