<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;

/**
 * The one canonical payroll period-lock contract (spec-02 §5.2, spec-03 §5.3,
 * spec-04 §4.2 + §4.2.1 delta 4). Implemented by Payroll (M4); used by Leave,
 * Attendance and Core. A date is locked when a submitted Slip Gaji covers it.
 */
interface PayrollLockQuery
{
    public function isLocked(Employee|int $employee, CarbonImmutable $date): bool;

    /**
     * @return list<CarbonImmutable>
     */
    public function lockedDatesIn(Employee|int $employee, DateRange $range): array;
}
