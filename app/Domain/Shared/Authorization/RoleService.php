<?php

declare(strict_types=1);

namespace App\Domain\Shared\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-organisation role administration on top of spatie teams mode
 * (team key = organisation_id). Reads use explicit queries so results never
 * depend on the request's current team or on cached relations.
 */
final class RoleService
{
    private const GUARD = 'web';

    /** @var array<string, list<string>> per-request memo of permissionsOf() */
    private array $memo = [];

    public function flush(): void
    {
        $this->memo = [];
    }

    /** Create every permission in the matrix (idempotent). */
    public function ensurePermissions(): void
    {
        foreach (PermissionMatrix::permissions() as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->memo = [];
    }

    /** Create (or refresh) the standard roles of an organisation with their permissions. */
    public function createRolesFor(int $organisationId): void
    {
        $this->ensurePermissions();

        foreach (PermissionMatrix::roles() as $roleName) {
            $role = Role::query()->firstOrCreate([
                'name' => $roleName,
                'guard_name' => self::GUARD,
                config('permission.column_names.team_foreign_key') => $organisationId,
            ]);
            $role->syncPermissions(PermissionMatrix::permissionsFor($roleName));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->memo = [];
    }

    public function roleId(int $organisationId, string $roleName): ?int
    {
        $id = DB::table(config('permission.table_names.roles'))
            ->where(config('permission.column_names.team_foreign_key'), $organisationId)
            ->where('name', $roleName)
            ->where('guard_name', self::GUARD)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function assign(User $user, int $organisationId, string $roleName): void
    {
        $roleId = $this->roleId($organisationId, $roleName);
        if ($roleId === null) {
            $this->createRolesFor($organisationId);
            $roleId = (int) $this->roleId($organisationId, $roleName);
        }

        DB::table(config('permission.table_names.model_has_roles'))->insertOrIgnore([
            config('permission.column_names.role_pivot_key') ?? 'role_id' => $roleId,
            'model_type' => $user->getMorphClass(),
            config('permission.column_names.model_morph_key') => $user->getKey(),
            config('permission.column_names.team_foreign_key') => $organisationId,
        ]);

        $user->forgetCachedRoles();
        $this->memo = [];
    }

    public function remove(User $user, int $organisationId, string $roleName): void
    {
        $roleId = $this->roleId($organisationId, $roleName);
        if ($roleId === null) {
            return;
        }

        DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $roleId)
            ->where('model_type', $user->getMorphClass())
            ->where(config('permission.column_names.model_morph_key'), $user->getKey())
            ->where(config('permission.column_names.team_foreign_key'), $organisationId)
            ->delete();

        $user->forgetCachedRoles();
        $this->memo = [];
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function sync(User $user, int $organisationId, array $roleNames): void
    {
        $current = $this->rolesOf($user, $organisationId);
        foreach (array_diff($current, $roleNames) as $name) {
            $this->remove($user, $organisationId, $name);
        }
        foreach (array_diff($roleNames, $current) as $name) {
            $this->assign($user, $organisationId, $name);
        }
    }

    public function hasRole(User $user, int $organisationId, string $roleName): bool
    {
        return in_array($roleName, $this->rolesOf($user, $organisationId), true);
    }

    /**
     * @return list<string>
     */
    public function rolesOf(User $user, int $organisationId): array
    {
        $tables = config('permission.table_names');

        return DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where('mhr.model_id', $user->getKey())
            ->where('mhr.organisation_id', $organisationId)
            ->orderBy('r.name')
            ->pluck('r.name')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    /**
     * Permission names the user holds in an organisation (all for Super Admin).
     *
     * @return list<string>
     */
    public function permissionsOf(User $user, int $organisationId): array
    {
        if ($user->is_super_admin) {
            return PermissionMatrix::permissions();
        }

        $key = $user->getKey().':'.$organisationId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $tables = config('permission.table_names');

        return $this->memo[$key] = DB::table($tables['model_has_roles'].' as mhr')
            ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
            ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where('mhr.model_id', $user->getKey())
            ->where('mhr.organisation_id', $organisationId)
            ->distinct()
            ->orderBy('p.name')
            ->pluck('p.name')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    public function can(User $user, int $organisationId, string $permission): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return in_array($permission, $this->permissionsOf($user, $organisationId), true);
    }
}
