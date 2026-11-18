<?php

use App\Domain\Core\Actions\SaveDepartment;
use App\Domain\Core\Actions\SaveEmergencyContacts;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DocumentType;
use App\Domain\Core\Models\EmployeeDocument;
use App\Domain\Core\Models\HrSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->org = inOrg(makeOrganisation());
    $this->hr = userIn($this->org, 'pengurus_hr');
});

// B8 #6
it('rewrites descendant paths when re-parenting and rejects cycles', function () {
    $save = app(SaveDepartment::class);
    $root = Department::query()->whereNull('parent_id')->firstOrFail();
    $a = $save->handle(null, ['code' => 'A', 'name' => 'A', 'parent_id' => $root->id]);
    $b = $save->handle(null, ['code' => 'B', 'name' => 'B', 'parent_id' => $root->id]);
    $a1 = $save->handle(null, ['code' => 'A1', 'name' => 'A1', 'parent_id' => $a->id]);
    $a11 = $save->handle(null, ['code' => 'A11', 'name' => 'A11', 'parent_id' => $a1->id]);

    $save->handle($a->fresh(), ['code' => 'A', 'name' => 'A', 'parent_id' => $b->id]);

    expect($a->fresh()->path)->toBe("/{$root->id}/{$b->id}/{$a->id}/")
        ->and($a11->fresh()->path)->toBe("/{$root->id}/{$b->id}/{$a->id}/{$a1->id}/{$a11->id}/")
        ->and($a11->fresh()->depth)->toBe(4)
        ->and($a11->fresh()->ancestorIds())->toBe([$a1->id, $a->id, $b->id, $root->id])
        ->and(fn () => $save->handle($a->fresh(), ['code' => 'A', 'name' => 'A', 'parent_id' => $a11->id]))->toThrow(ValidationException::class);
});

// B8 #7
it('refuses to deactivate a department with active employees', function () {
    $dept = Department::factory()->create();
    employee(['department_id' => $dept->id]);

    actingInOrg($this->hr, $this->org)->put("/departments/{$dept->id}", [
        'code' => $dept->code, 'name' => $dept->name, 'parent_id' => $dept->parent_id, 'is_active' => false,
    ])->assertSessionHasErrors('is_active');
    expect($dept->fresh()->is_active)->toBeTrue();
});

// B8 #24
it('validates uploads, keeps files private and forbids other employees from downloading', function () {
    Storage::fake('local');
    $owner = userIn($this->org, 'kakitangan');
    $e = employee([], $owner);
    $type = DocumentType::query()->firstOrFail();

    actingInOrg($this->hr, $this->org)->post("/employees/{$e->id}/documents", [
        'file' => UploadedFile::fake()->create('besar.pdf', 11000, 'application/pdf'), 'document_type_id' => $type->id,
    ])->assertSessionHasErrors('file');
    $this->post("/employees/{$e->id}/documents", [
        'file' => UploadedFile::fake()->create('skrip.exe', 10, 'application/octet-stream'), 'document_type_id' => $type->id,
    ])->assertSessionHasErrors('file');
    $this->post("/employees/{$e->id}/documents", [
        'file' => UploadedFile::fake()->create('mykad.pdf', 100, 'application/pdf'), 'document_type_id' => $type->id, 'title' => 'MyKad',
    ])->assertSessionHasNoErrors();

    $doc = EmployeeDocument::query()->firstOrFail();
    Storage::disk('local')->assertExists($doc->file_path);
    expect($doc->file_path)->toStartWith("organisations/{$this->org->id}/employees/{$e->id}/");

    actingInOrg($owner, $this->org)->get("/employee-documents/{$doc->id}/download")->assertOk();
    $stranger = userIn($this->org, 'kakitangan');
    employee([], $stranger);
    actingInOrg($stranger, $this->org)->get("/employee-documents/{$doc->id}/download")->assertForbidden();
    expect($this->get('/storage/'.$doc->file_path)->status())->toBeIn([403, 404]);
});

// B8 #25
it('requires exactly one primary emergency contact', function () {
    $e = employee();
    $save = app(SaveEmergencyContacts::class);
    $c = fn (bool $primary) => ['name' => 'Waris', 'relationship' => 'parent', 'phone' => '0123', 'is_primary' => $primary];

    expect(fn () => $save->handle($e, [$c(false), $c(false)]))->toThrow(ValidationException::class)
        ->and(fn () => $save->handle($e, [$c(true), $c(true)]))->toThrow(ValidationException::class);
    $save->handle($e, [$c(true), $c(false)]);
    expect($e->emergencyContacts()->where('is_primary', true)->count())->toBe(1);
});

it('renders master, department, organisation and settings screens', function () {
    actingInOrg($this->hr, $this->org);
    foreach (['branches', 'designations', 'grades', 'employment-types', 'document-types'] as $kind) {
        $this->get("/{$kind}")->assertOk()->assertInertia(fn ($p) => $p->component('Core/Masters/Index')->where('kind', $kind));
    }
    $this->post('/branches', ['code' => 'KTN', 'name' => 'Kuantan', 'state' => 'PHG', 'is_active' => true])->assertSessionHasNoErrors();
    $this->post('/grades', ['code' => 'G9', 'name' => 'Gred 9', 'rank' => 9, 'salary_min' => '1000', 'salary_max' => '2000.50', 'is_active' => true])->assertSessionHasNoErrors();
    $this->get('/departments')->assertOk();
    $this->get('/settings/hr')->assertOk();
    $this->put('/settings/hr', array_merge(HrSettings::current()->only([
        'employee_no_format', 'retirement_age', 'default_probation_months', 'standard_daily_hours', 'standard_weekly_hours',
        'min_employment_age', 'remind_probation_days', 'remind_contract_days', 'remind_work_pass_days', 'remind_document_days', 'remind_retirement_days',
    ]), ['employee_no_mode' => 'auto', 'holiday_notice' => 'weekly', 'self_service_editable' => ['mobile_phone']]))->assertSessionHasNoErrors();
    expect(HrSettings::current()->selfServiceFields())->toBe(['mobile_phone']);
    $this->get('/organisations')->assertOk();
    $this->get('/users')->assertOk();
    $this->get('/audit')->assertOk();
});
