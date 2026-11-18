<?php

declare(strict_types=1);

namespace App\Domain\Leave\Contracts;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Support\LeaveDay;
use Carbon\CarbonInterface;

/**
 * Leave → Attendance query (spec-02 §5.1): Attendance calls it before manual
 * marking or auto-attendance and never overwrites leave-linked rows.
 */
interface LeaveQuery
{
    public function approvedLeaveOn(Employee $employee, CarbonInterface $date): ?LeaveDay;
}
