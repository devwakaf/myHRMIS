<?php

declare(strict_types=1);

namespace App\Domain\Core\Policies;

use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;

final class HolidayListAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('core.holiday.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('core.holiday.manage');
    }

    public function update(User $user, HolidayListAssignment $assignment): bool
    {
        return $this->same($assignment) && $user->can('core.holiday.manage') && $assignment->isDraft();
    }

    public function delete(User $user, HolidayListAssignment $assignment): bool
    {
        return $this->update($user, $assignment);
    }

    public function submit(User $user, HolidayListAssignment $assignment): bool
    {
        return $this->same($assignment) && $user->can('core.holiday.manage');
    }

    public function cancel(User $user, HolidayListAssignment $assignment): bool
    {
        return $this->same($assignment) && $user->can('core.holiday-assignment.cancel');
    }

    private function same(HolidayListAssignment $assignment): bool
    {
        return OrganisationContext::has() && $assignment->organisation_id === OrganisationContext::id();
    }
}
