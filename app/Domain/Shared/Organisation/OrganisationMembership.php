<?php

declare(strict_types=1);

namespace App\Domain\Shared\Organisation;

use App\Domain\Core\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A user is a member of an Organisasi when they hold any role in it (spatie teams).
 * Super Admin is a global flag and sees every organisation.
 */
final class OrganisationMembership
{
    public function isMember(User $user, int $organisationId): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return in_array($organisationId, $this->organisationIdsFor($user), true);
    }

    /**
     * @return list<int>
     */
    public function organisationIdsFor(User $user): array
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->distinct()
            ->pluck('organisation_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Organisations the user may switch to (active ones; all for Super Admin).
     *
     * @return Collection<int, Organisation>
     */
    public function organisationsFor(User $user): Collection
    {
        $query = Organisation::query()->where('is_active', true)->orderBy('name');

        if (! $user->is_super_admin) {
            $query->whereIn('id', $this->organisationIdsFor($user));
        }

        return $query->get();
    }

    /**
     * Ids of active users who are members of the organisation.
     *
     * @return list<int>
     */
    public function memberUserIds(int $organisationId): array
    {
        return DB::table(config('permission.table_names.model_has_roles').' as mhr')
            ->join('users', 'users.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->where('mhr.organisation_id', $organisationId)
            ->where('users.is_active', true)
            ->distinct()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Ids of active users holding a named role in the organisation.
     *
     * @return list<int>
     */
    public function usersWithRole(int $organisationId, string $roleName): array
    {
        $tables = config('permission.table_names');

        return DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->join('users', 'users.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->where('mhr.organisation_id', $organisationId)
            ->where('r.name', $roleName)
            ->where('users.is_active', true)
            ->distinct()
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Ids of active users who hold, through any role in the organisation, a permission.
     *
     * @return list<int>
     */
    public function usersWithPermission(int $organisationId, string $permission): array
    {
        $tables = config('permission.table_names');

        return DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->join('users', 'users.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->where('mhr.organisation_id', $organisationId)
            ->where('p.name', $permission)
            ->where('users.is_active', true)
            ->distinct()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
