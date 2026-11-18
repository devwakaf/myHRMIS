<?php

use App\Domain\Core\Actions\CreateEmployee;
use App\Domain\Core\Actions\UpdateHrSettings;
use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Shared\Audit\Activity;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->org = inOrg(makeOrganisation(['short_code' => 'TST']));
    $this->hr = userIn($this->org, ['pengurus_hr']);
    $this->department = Department::factory()->create();
    $this->designation = Designation::factory()->create();
    $this->permanent = EmploymentType::query()->where('code', 'TETAP')->firstOrFail();
    $this->contract = EmploymentType::query()->where('code', 'KONTRAK')->firstOrFail();
    $this->bank = Bank::factory()->create();
});

function payload(array $overrides = []): array
{
    $t = test();

    return array_replace_recursive([
        'full_name' => 'Aminah binti Yusof',
        'identity_type' => 'mykad',
        'ic_no' => '900101-06-1234',
        'citizenship_status' => 'citizen',
        'nationality' => 'MY',
        'marital_status' => 'single',
        'mobile_phone' => '012-3456789',
        'date_of_joining' => '2024-01-02',
        'department_id' => $t->department->id,
        'designation_id' => $t->designation->id,
        'employment_type_id' => $t->permanent->id,
        'salary_payment_mode' => 'bank_transfer',
        'bank_id' => $t->bank->id,
        'bank_account_no' => '112233445566',
        'bank_account_name' => 'Aminah binti Yusof',
        'tax' => ['tax_residency' => 'resident', 'mtd_scheme' => 'normal', 'pcb_category' => 1],
    ], $overrides);
}

function errorsOf(callable $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('creates an employee from the form with derived fields, number, tax profile and work history', function () {
    actingInOrg($this->hr, $this->org)->post('/employees', payload())->assertRedirect();

    $e = Employee::query()->firstOrFail();
    expect($e->employee_no)->toBe('TST-00001')
        ->and($e->ic_no)->toBe('900101061234')
        ->and($e->date_of_birth->toDateString())->toBe('1990-01-01')
        ->and($e->gender->value)->toBe('female')
        ->and($e->retirement_date->toDateString())->toBe('2050-01-01')
        ->and($e->probation_end_date->toDateString())->toBe('2024-04-02')
        ->and($e->taxProfile)->not->toBeNull()
        ->and($e->workHistory()->count())->toBe(1);
});

// B8 #11
it('saves an unknown place-of-birth code with a warning', function () {
    actingInOrg($this->hr, $this->org)->post('/employees', payload(['ic_no' => '900101171235']))
        ->assertSessionHas('warnings', fn ($w) => str_contains(implode(' ', $w), '17'));
    expect(Employee::query()->count())->toBe(1);
});

// B8 #12
it('rejects duplicate IC among non-left employees, allows it after leaving and warns across organisations', function () {
    $create = app(CreateEmployee::class);
    $create->handle(payload());

    expect(errorsOf(fn () => $create->handle(payload())))->toHaveKey('ic_no');

    Employee::query()->update(['status' => 'left']);
    $create->handle(payload());
    expect(Employee::query()->count())->toBe(2);

    $other = makeOrganisation();
    $warnings = OrganisationContext::run($other, function () {
        $d = Department::factory()->create();
        $des = Designation::factory()->create();

        return app(CreateEmployee::class)->handle(payload(['department_id' => $d->id, 'designation_id' => $des->id,
            'employment_type_id' => EmploymentType::query()->where('code', 'TETAP')->value('id')]))['warnings'];
    });
    expect(implode(' ', $warnings))->toContain(__('core.warnings.duplicate_identity_other_org'));
});

// B8 #13
it('requires passport and work pass data for foreigners and MY nationality for citizens', function () {
    $create = app(CreateEmployee::class);

    $errors = errorsOf(fn () => $create->handle(payload(['identity_type' => 'passport', 'ic_no' => null, 'citizenship_status' => 'foreign',
        'nationality' => 'ID', 'date_of_birth' => '1990-01-01', 'gender' => 'male'])));
    expect($errors)->toHaveKeys(['passport_no', 'passport_country', 'work_pass_expiry', 'work_pass_type']);

    expect(errorsOf(fn () => $create->handle(payload(['nationality' => 'SG']))))->toHaveKey('nationality');
});

// B8 #14
it('requires a contract end date for contract employment types', function () {
    expect(errorsOf(fn () => app(CreateEmployee::class)->handle(payload(['employment_type_id' => $this->contract->id]))))
        ->toHaveKey('contract_end_date');
});

// B8 #15
it('rejects reports_to self, cycles and inactive managers', function () {
    $create = app(CreateEmployee::class);
    $manager = $create->handle(payload(['ic_no' => '800101061231']))['employee'];
    $staff = $create->handle(payload(['ic_no' => '850101061233', 'reports_to_id' => $manager->id]))['employee'];

    $this->hr->update([]);
    actingInOrg($this->hr, $this->org)->post("/employees/{$manager->id}/correction", [
        'department_id' => $manager->department_id, 'designation_id' => $manager->designation_id,
        'employment_type_id' => $manager->employment_type_id, 'reports_to_id' => $staff->id, 'reason' => 'Uji',
    ])->assertSessionHasErrors('reports_to_id');

    $this->post("/employees/{$manager->id}/correction", [
        'department_id' => $manager->department_id, 'designation_id' => $manager->designation_id,
        'employment_type_id' => $manager->employment_type_id, 'reports_to_id' => $manager->id, 'reason' => 'Uji',
    ])->assertSessionHasErrors('reports_to_id');

    $manager->update(['status' => 'suspended']);
    expect(errorsOf(fn () => $create->handle(payload(['ic_no' => '860101061235', 'reports_to_id' => $manager->id]))))->toHaveKey('reports_to_id');
});

// B8 #16
it('recomputes the retirement date when DOB or retirement age changes', function () {
    $e = app(CreateEmployee::class)->handle(payload())['employee'];
    expect($e->retirement_date->toDateString())->toBe('2050-01-01');

    app(UpdateHrSettings::class)->handle(HrSettings::current(), ['retirement_age' => 65]);
    expect($e->fresh()->retirement_date->toDateString())->toBe('2055-01-01');

    actingInOrg($this->hr, $this->org)->put("/employees/{$e->id}", payload(['ic_no' => '920202061234']))->assertSessionHasNoErrors();
    expect($e->fresh()->retirement_date->toDateString())->toBe('2057-02-02');
});

// B8 #17, #18
it('requires spouse_working for married employees and a reason to override the PCB category', function () {
    $create = app(CreateEmployee::class);

    expect(errorsOf(fn () => $create->handle(payload(['marital_status' => 'married']))))->toHaveKey('tax.spouse_working');
    expect(errorsOf(fn () => $create->handle(payload(['marital_status' => 'married', 'tax' => ['spouse_working' => false, 'pcb_category' => 3]]))))
        ->toHaveKey('tax.pcb_category_override_reason');

    $e = $create->handle(payload(['marital_status' => 'married', 'tax' => ['spouse_working' => false, 'pcb_category' => 2]]))['employee'];
    expect($e->taxProfile->pcb_category)->toBe(2);
});

// B8 #61f
it('rejects continuous service after joining and uses it for the service start', function () {
    expect(errorsOf(fn () => app(CreateEmployee::class)->handle(payload(['continuous_service_from' => '2024-02-01']))))->toHaveKey('continuous_service_from');

    $e = app(CreateEmployee::class)->handle(payload(['continuous_service_from' => '2020-05-01']))['employee'];
    expect($e->serviceStart()->toDateString())->toBe('2020-05-01');
});

// B8 #19
it('applies the role matrix to employee data', function () {
    $e = app(CreateEmployee::class)->handle(payload())['employee'];
    $hre = userIn($this->org, 'eksekutif_hr');
    $fin = userIn($this->org, 'kewangan');
    $kktUser = userIn($this->org, 'kakitangan');
    $own = app(CreateEmployee::class)->handle(payload(['ic_no' => '910101061235']), ['user_id' => $kktUser->id])['employee'];

    expect($hre->can('viewCompensation', $e))->toBeFalse()
        ->and($this->hr->can('viewCompensation', $e))->toBeTrue();

    actingInOrg($fin, $this->org)->get('/employees')->assertForbidden();
    actingInOrg($kktUser, $this->org)->get('/employees')->assertForbidden();
    $this->get("/employees/{$own->id}")->assertOk();
    $this->get("/employees/{$e->id}")->assertForbidden();
});

// B8 #20
it('masks IC numbers without view-sensitive and leaves sensitive columns out of exports', function () {
    app(CreateEmployee::class)->handle(payload());
    $user = userIn($this->org, 'kakitangan');
    app(RoleService::class)->createRolesFor($this->org->id);
    // a role with core.employee.view but not view-sensitive does not exist in the matrix, so grant it directly
    $role = Role::query()->create(['name' => 'pembaca', 'guard_name' => 'web', 'organisation_id' => $this->org->id]);
    $role->givePermissionTo('core.employee.view');
    app(RoleService::class)->assign($user, $this->org->id, 'pembaca');

    actingInOrg($user, $this->org)->get('/employees')->assertInertia(fn ($page) => $page->where('employees.data.0.ic_no', '********1234'));

    $response = $this->get('/employees/export?format=csv');
    $csv = file_get_contents($response->baseResponse->getFile()->getPathname());
    expect($csv)->not->toContain('900101061234')->not->toContain(__('core.employee.columns.ic_no'));

    actingInOrg($this->hr, $this->org)->get('/employees')->assertInertia(fn ($page) => $page->where('employees.data.0.ic_no', '900101061234'));
});

// B8 #21
it('logs bank account changes without the account numbers', function () {
    $e = app(CreateEmployee::class)->handle(payload())['employee'];
    actingInOrg($this->hr, $this->org)->put("/employees/{$e->id}", payload(['bank_account_no' => '998877665544']))->assertSessionHasNoErrors();

    $log = Activity::query()->where('subject_type', $e->getMorphClass())->where('subject_id', $e->id)->where('event', 'updated')->latest('id')->first();
    $json = json_encode($log->properties);
    expect($json)->not->toContain('998877665544')->not->toContain('112233445566')
        ->and($log->properties['attributes']['bank_account_no'])->toBe('changed');
});

// B8 #22
it('rejects self-service edits of fields that are not allowed', function () {
    $user = userIn($this->org, 'kakitangan');
    $e = app(CreateEmployee::class)->handle(payload(), ['user_id' => $user->id])['employee'];

    actingInOrg($user, $this->org)->put('/me', ['department_id' => $this->department->id])->assertSessionHasErrors('department_id');
    $this->put('/me', ['mobile_phone' => '019-9999999'])->assertSessionHasNoErrors();
    expect($e->fresh()->mobile_phone)->toBe('019-9999999');
});

// B8 #23
it('gives a reports-to manager no access to the subordinate record', function () {
    $mgrUser = userIn($this->org, 'kakitangan');
    $mgr = app(CreateEmployee::class)->handle(payload(['ic_no' => '800101061231']), ['user_id' => $mgrUser->id])['employee'];
    $staff = app(CreateEmployee::class)->handle(payload(['ic_no' => '850101061233', 'reports_to_id' => $mgr->id]))['employee'];

    actingInOrg($mgrUser, $this->org)->get("/employees/{$staff->id}")->assertForbidden();
});

it('blocks assignment changes through the plain edit form', function () {
    $e = app(CreateEmployee::class)->handle(payload())['employee'];
    $other = Department::factory()->create();

    actingInOrg($this->hr, $this->org)->put("/employees/{$e->id}", payload(['department_id' => $other->id]))->assertSessionHasErrors('department_id');
});

// B8 #61e
it('shows name_en in English when filled, else the BM name', function () {
    $designation = Designation::factory()->create(['name' => 'Pengurus', 'name_en' => 'Manager']);
    $dept = Department::factory()->create(['name' => 'Kewangan', 'name_en' => null]);

    app()->setLocale('en');
    expect($designation->displayName())->toBe('Manager')->and($dept->displayName())->toBe('Kewangan');
    app()->setLocale('ms');
    expect($designation->displayName())->toBe('Pengurus');
});

it('renders the employee screens', function () {
    $e = app(CreateEmployee::class)->handle(payload())['employee'];

    actingInOrg($this->hr, $this->org)->get('/employees')->assertOk()->assertInertia(fn ($p) => $p->component('Core/Employees/Index'));
    $this->get('/employees/create')->assertOk()->assertInertia(fn ($p) => $p->component('Core/Employees/Form'));
    $this->get("/employees/{$e->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Core/Employees/Show')->where('employee.ic_no', '900101061234'));
    $this->get("/employees/{$e->id}/edit")->assertOk();
});
