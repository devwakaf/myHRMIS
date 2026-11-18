<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengguna (A7 system.users.manage): members of the active Organisasi and their
 * roles there. Super Admin cannot be granted from here.
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly OrganisationMembership $membership,
    ) {}

    public function index(Request $request): Response
    {
        $this->requirePermission('system.users.manage');
        $orgId = OrganisationContext::id();
        $search = trim((string) $request->query('search', ''));

        $memberIds = DB::table(config('permission.table_names.model_has_roles'))
            ->where('organisation_id', $orgId)->where('model_type', (new User)->getMorphClass())
            ->distinct()->pluck('model_id');

        $users = User::query()
            ->whereIn('id', $memberIds)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'locale' => $u->locale,
                'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at?->format('Y-m-d H:i'),
                'roles' => $this->roles->rolesOf($u, $orgId),
                'employee' => Employee::query()->where('user_id', $u->id)->value('full_name'),
            ]);

        return Inertia::render('System/Users/Index', [
            'users' => $users,
            'filters' => ['search' => $search],
            'roleOptions' => array_map(fn ($r) => ['value' => $r, 'label' => __('common.roles.'.$r)], PermissionMatrix::assignableRoles()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('system.users.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::defaults()],
            'locale' => ['required', Rule::in(config('myhrmis.locales'))],
            'roles' => ['array'],
            'roles.*' => [Rule::in(PermissionMatrix::assignableRoles())],
        ]);

        DB::transaction(function () use ($data): void {
            $user = User::query()->where('email', strtolower($data['email']))->first();
            if ($user === null) {
                abort_if(blank($data['password'] ?? null), 422, (string) __('validation.required', ['attribute' => __('validation.attributes.password')]));
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => strtolower($data['email']),
                    'password' => $data['password'],
                    'locale' => $data['locale'],
                    'is_active' => true,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $roles = $data['roles'] ?? [];
            if ($roles === []) {
                $roles = [PermissionMatrix::KAKITANGAN];
            }
            foreach ($roles as $role) {
                $this->roles->assign($user, OrganisationContext::id(), $role);
            }
        });

        return $this->done('common.saved');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->requirePermission('system.users.manage');
        abort_unless($this->membership->isMember($user, OrganisationContext::id()) && ! $user->is_super_admin, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', Rule::in(config('myhrmis.locales'))],
            'is_active' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => [Rule::in(PermissionMatrix::assignableRoles())],
            'password' => ['nullable', 'string', Password::defaults()],
        ]);

        $user->fill(['name' => $data['name'], 'locale' => $data['locale']]);
        if (array_key_exists('is_active', $data)) {
            $user->is_active = (bool) $data['is_active'];
        }
        if (! blank($data['password'] ?? null)) {
            $user->password = $data['password'];
        }
        $user->save();

        // Keep the derived pelulus role as it is; it is managed by the approval engine.
        $keepPelulus = $this->roles->hasRole($user, OrganisationContext::id(), PermissionMatrix::PELULUS);
        $roles = array_values(array_unique(array_merge($data['roles'] ?? [], $keepPelulus ? [PermissionMatrix::PELULUS] : [])));
        if ($roles !== []) {
            $this->roles->sync($user, OrganisationContext::id(), $roles);
        }

        return $this->done('common.saved');
    }
}
