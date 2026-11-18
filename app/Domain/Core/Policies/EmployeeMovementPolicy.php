<?php

declare(strict_types=1);

namespace App\Domain\Core\Policies;

use App\Domain\Core\Models\EmployeeMovement;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;

final class EmployeeMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('core.movement.create') || $user->can('core.employee.view');
    }

    public function view(User $user, EmployeeMovement $movement): bool
    {
        return $this->same($movement) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('core.movement.create');
    }

    public function update(User $user, EmployeeMovement $movement): bool
    {
        return $this->same($movement) && $user->can('core.movement.create') && $movement->isDraft();
    }

    public function delete(User $user, EmployeeMovement $movement): bool
    {
        return $this->update($user, $movement);
    }

    public function submit(User $user, EmployeeMovement $movement): bool
    {
        return $this->same($movement) && $user->can('core.movement.submit');
    }

    public function cancel(User $user, EmployeeMovement $movement): bool
    {
        return $this->same($movement) && $user->can('core.movement.cancel');
    }

    private function same(EmployeeMovement $movement): bool
    {
        return OrganisationContext::has() && $movement->organisation_id === OrganisationContext::id();
    }
}
