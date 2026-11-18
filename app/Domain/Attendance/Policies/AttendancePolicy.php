<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Models\Attendance;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;

/**
 * Rekod Kehadiran / Log Check-in access (spec-03 §8). Supervisors through
 * reports_to get nothing (USER); exact coordinates need view-coordinates
 * (Kakitangan: own only).
 */
final class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('attendance.view-all');
    }

    public function view(User $user, Attendance $attendance): bool
    {
        return $attendance->organisation_id === OrganisationContext::idOrNull()
            && ($user->can('attendance.view-all') || $this->owns($user, $attendance->employee_id));
    }

    public function viewEmployee(User $user, Employee $employee): bool
    {
        return $user->can('attendance.view-all') || $this->owns($user, $employee->id);
    }

    /** HR Manager (view-coordinates) sees every punch's coordinates; Kakitangan only their own. */
    public function viewCoordinates(User $user, int $employeeId): bool
    {
        return $user->can('attendance.location.view-coordinates') || $this->owns($user, $employeeId);
    }

    public function owns(User $user, int $employeeId): bool
    {
        $userId = Employee::acrossOrganisations()->whereKey($employeeId)->value('user_id');

        return $userId !== null && (int) $userId === $user->id;
    }
}
