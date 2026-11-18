<?php

use App\Domain\Core\Actions\CreateOrganisation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Arch');
pest()->extend(TestCase::class)->in('Unit');

require_once __DIR__.'/Helpers/LeaveHelpers.php';
require_once __DIR__.'/Helpers/AttendanceHelpers.php';
require_once __DIR__.'/Helpers/PayrollHelpers.php';

/*
| Helpers shared by the M0/M1 test suite.
*/

/** A fully set-up Organisasi (roles, HR settings, default workflows, root department…). */
function makeOrganisation(array $attributes = []): Organisation
{
    $data = Organisation::factory()->make($attributes)->getAttributes();
    unset($data['created_by'], $data['updated_by']);

    return app(CreateOrganisation::class)->handle($data);
}

/** Set the active organisation context for direct (non-HTTP) calls. */
function inOrg(Organisation $organisation): Organisation
{
    OrganisationContext::set($organisation);

    return $organisation;
}

/**
 * A user holding the given roles in an organisation.
 *
 * @param  string|list<string>  $roles
 */
function userIn(Organisation $organisation, string|array $roles = 'kakitangan', array $attributes = []): User
{
    $user = User::factory()->create($attributes + ['current_organisation_id' => $organisation->id]);
    foreach ((array) $roles as $role) {
        app(RoleService::class)->assign($user, $organisation->id, $role);
    }

    return $user;
}

function superAdmin(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_super_admin' => true])->save();

    return $user;
}

/** Act as a user inside an organisation (context set for direct calls too). */
function actingInOrg(User $user, Organisation $organisation): TestCase
{
    $user->forceFill(['current_organisation_id' => $organisation->id])->save();
    inOrg($organisation);

    return test()->actingAs($user);
}

/** An employee in the active organisation, optionally linked to a user. */
function employee(array $attributes = [], ?User $user = null): Employee
{
    return Employee::factory()->create($attributes + ['user_id' => $user?->id]);
}
