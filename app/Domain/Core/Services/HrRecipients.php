<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * HR Manager / HR Executive users of an organisation (reminder recipients).
 */
final class HrRecipients
{
    public function __construct(private readonly OrganisationMembership $membership) {}

    /**
     * @return Collection<int, User>
     */
    public function for(int $organisationId): Collection
    {
        $ids = array_unique(array_merge(
            $this->membership->usersWithRole($organisationId, PermissionMatrix::PENGURUS_HR),
            $this->membership->usersWithRole($organisationId, PermissionMatrix::EKSEKUTIF_HR),
        ));

        return User::query()->whereIn('id', $ids)->where('is_active', true)->get();
    }
}
