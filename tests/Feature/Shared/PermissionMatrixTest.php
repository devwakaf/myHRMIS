<?php

use App\Domain\Shared\Authorization\PermissionMatrix;

/*
| Matrix test generated from spec-00 A7 (plus the "+" rows of spec-02/03/04):
| every role holds exactly the permissions its row lists in its own Organisasi,
| and nothing in another Organisasi.
*/

it('grants each role exactly its matrix permissions in its organisation only', function (string $role) {
    $org = makeOrganisation();
    $other = makeOrganisation();
    $user = userIn($org, $role);

    inOrg($org);
    $user->forgetCachedRoles();
    foreach (PermissionMatrix::matrix() as $permission => $roles) {
        expect($user->can($permission))->toBe(in_array($role, $roles, true), "{$role} / {$permission}");
    }

    inOrg($other);
    $user->forgetCachedRoles();
    expect($user->can('approvals.inbox'))->toBeFalse();
})->with(PermissionMatrix::roles());

it('keeps key A7 cells', function () {
    $m = PermissionMatrix::matrix();

    expect($m['core.employee.view-compensation'])->not->toContain('eksekutif_hr')
        ->and($m['core.employee.view'])->not->toContain('kewangan')
        ->and($m['payroll.run.prepare'])->toBe(['pegawai_payroll'])
        ->and($m['payroll.run.submit'])->toBe(['pelulus_payroll'])
        ->and($m['leave.application.approve-any'])->toBe(['pengurus_hr'])
        ->and($m['system.organisations.manage'])->toBe([]);
});
