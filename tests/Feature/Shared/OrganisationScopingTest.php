<?php

use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DocumentType;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Approval\Models\ApprovalWorkflow;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\MissingOrganisationContext;
use App\Domain\Shared\Organisation\OrganisationContext;

// B8 #1, #61
it('seeds roles, settings, workflows, document types, employment types and a root department on create', function () {
    $org = makeOrganisation(['name' => 'Org Ujian']);

    OrganisationContext::run($org, function () {
        expect(HrSettings::query()->count())->toBe(1)
            ->and(DocumentType::query()->count())->toBe(8)
            ->and(EmploymentType::query()->pluck('code')->sort()->values()->all())->toBe(['KONTRAK', 'PELATIH', 'SAMBILAN', 'TETAP'])
            ->and(Department::query()->whereNull('parent_id')->first()->name)->toBe('Org Ujian');

        $workflows = ApprovalWorkflow::query()->where('is_active', true)->with('steps')->get();
        expect($workflows->pluck('approval_type')->sort()->values()->all())
            ->toBe(['attendance_request', 'compensatory_leave', 'leave', 'overtime_claim', 'shift_request'])
            // A6.9 default for Leave types; Attendance seeds its own two-step routes (spec-03 §4.12, lead instruction).
            ->and($workflows->whereIn('approval_type', ['leave', 'compensatory_leave'])->every(fn ($w) => $w->name === 'Kelulusan Standard' && $w->steps->count() === 1
                && $w->steps[0]->rule_type->value === 'named_or_department'
                && $w->steps[0]->config('selection') === 'applicant_chooses'
                && $w->steps[0]->on_no_approver->value === 'fail'))->toBeTrue()
            ->and($workflows->whereIn('approval_type', ['attendance_request', 'shift_request', 'overtime_claim'])
                ->every(fn ($w) => $w->steps->count() === 2))->toBeTrue();
    });

    foreach (PermissionMatrix::roles() as $role) {
        expect(app(RoleService::class)->roleId($org->id, $role))->not->toBeNull();
    }
});

// B8 #2
it('hides other organisations from a member of one organisation', function () {
    $a = makeOrganisation();
    $b = makeOrganisation();
    $employeeB = OrganisationContext::run($b, fn () => employee());

    $user = userIn($a, 'pengurus_hr');

    actingInOrg($user, $a)->get("/employees/{$employeeB->id}")->assertNotFound();
    $this->put('/organisation/current', ['organisation_id' => $b->id])->assertForbidden();
    expect($user->fresh()->current_organisation_id)->toBe($a->id);
});

// B8 #3
it('throws when an org-scoped model is queried without context', function () {
    makeOrganisation();
    OrganisationContext::clear();

    expect(fn () => Employee::query()->count())->toThrow(MissingOrganisationContext::class);
});

// B8 #4
it('lets the Super Admin switch to any organisation', function () {
    $a = makeOrganisation();
    $b = makeOrganisation();
    $admin = superAdmin();

    $this->actingAs($admin)->put('/organisation/current', ['organisation_id' => $b->id])->assertRedirect('/dashboard');
    expect($admin->fresh()->current_organisation_id)->toBe($b->id);
    $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('organisation.id', $b->id));
});

it('lets the Super Admin create organisations through the screen', function () {
    $admin = superAdmin();

    $this->actingAs($admin)->post('/organisations', [
        'name' => 'Organisasi Baharu', 'short_code' => 'OB1', 'registration_type' => 'company',
        'address_line1' => 'Jalan 1', 'postcode' => '25000', 'city' => 'Kuantan', 'state' => 'PHG',
        'daily_rate_basis' => 'fixed_divisor', 'daily_rate_divisor' => '26',
    ])->assertRedirect();

    expect(Organisation::query()->where('short_code', 'OB1')->exists())->toBeTrue();
});

// B8 #5
it('locks the short code after the first employee exists', function () {
    $org = makeOrganisation(['short_code' => 'ABC']);
    $admin = superAdmin();
    $payload = ['name' => $org->name, 'registration_type' => 'company', 'address_line1' => 'Jalan 1', 'postcode' => '25000',
        'city' => 'Kuantan', 'state' => 'PHG', 'daily_rate_basis' => 'fixed_divisor', 'daily_rate_divisor' => '26'];

    $this->actingAs($admin)->put("/organisations/{$org->id}", $payload + ['short_code' => 'XYZ'])->assertSessionHasNoErrors();
    expect($org->fresh()->short_code)->toBe('XYZ');

    OrganisationContext::run($org, fn () => employee());

    $this->put("/organisations/{$org->id}", $payload + ['short_code' => 'QQQ'])->assertSessionHasErrors('short_code');
    expect($org->fresh()->short_code)->toBe('XYZ');
});
