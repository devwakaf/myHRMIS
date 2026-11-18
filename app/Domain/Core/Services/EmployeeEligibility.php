<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * B6: employed on a date = joined ≤ date ≤ relieving (or open).
 */
final class EmployeeEligibility
{
    public static function isActiveOn(Employee $employee, CarbonInterface|string $date): bool
    {
        $day = CarbonImmutable::parse($date instanceof CarbonInterface ? $date->format('Y-m-d') : $date)->startOfDay();

        if ($day->lessThan($employee->date_of_joining)) {
            return false;
        }

        return $employee->relieving_date === null || $day->lessThanOrEqualTo($employee->relieving_date);
    }
}
