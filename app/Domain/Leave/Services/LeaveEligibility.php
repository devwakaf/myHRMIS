<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Models\LeaveType;

/**
 * Type eligibility by gender and marital status (spec-02 §2.1, §3.9 step 1).
 */
final class LeaveEligibility
{
    /** Null when eligible, else a translation key. */
    public static function reason(Employee $employee, LeaveType $type): ?string
    {
        if ($type->eligible_gender !== null && $employee->gender->value !== $type->eligible_gender) {
            return 'leave.errors.not_eligible_gender';
        }

        $statuses = $type->eligible_marital_statuses ?? [];
        if ($statuses !== [] && ! in_array($employee->marital_status->value, $statuses, true)) {
            return 'leave.errors.not_eligible_marital';
        }

        return null;
    }

    public static function isEligible(Employee $employee, LeaveType $type): bool
    {
        return self::reason($employee, $type) === null;
    }
}
