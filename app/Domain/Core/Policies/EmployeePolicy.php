<?php

declare(strict_types=1);

namespace App\Domain\Core\Policies;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;

/**
 * Kakitangan access (A7). Own record = O; supervisors get nothing through
 * reports_to (USER DECISION); approvers see requests, never the profile (A6.8).
 */
final class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('core.employee.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        if (! $this->sameOrganisation($employee)) {
            return false;
        }

        return $user->can('core.employee.view') || $this->isOwn($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->can('core.employee.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee) && $user->can('core.employee.manage');
    }

    public function correct(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee) && $user->can('core.employee.correct');
    }

    public function separate(User $user, Employee $employee): bool
    {
        return $this->update($user, $employee);
    }

    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee)
            && ($user->can('core.employee.view-sensitive') || $this->isOwn($user, $employee));
    }

    public function viewCompensation(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee)
            && ($user->can('core.employee.view-compensation') || $this->isOwn($user, $employee));
    }

    public function viewDocuments(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee)
            && ($user->can('core.employee.documents.manage') || $user->can('core.employee.view') || $this->isOwn($user, $employee));
    }

    public function manageDocuments(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee) && $user->can('core.employee.documents.manage');
    }

    public function selfEdit(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee) && $this->isOwn($user, $employee) && $user->can('core.employee.self-edit');
    }

    public function viewAudit(User $user, Employee $employee): bool
    {
        return $this->sameOrganisation($employee) && $user->can('system.audit.view');
    }

    private function isOwn(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === $user->id;
    }

    private function sameOrganisation(Employee $employee): bool
    {
        return OrganisationContext::has() && $employee->organisation_id === OrganisationContext::id();
    }
}
