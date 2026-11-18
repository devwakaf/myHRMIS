<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\SaveDepartment;
use App\Domain\Core\Actions\SaveDepartmentApprovers;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Http\Resources\Lookup;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DepartmentApprover;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jabatan tree (B1.3, B4) with the "Pelulus Jabatan" side panel.
 */
final class DepartmentController extends Controller
{
    public function index(Request $request, ApprovalTypeRegistry $registry): Response
    {
        $this->requirePermission('core.masters.manage', 'core.masters.view');

        $counts = Employee::query()->where('status', '!=', EmployeeStatus::Left->value)
            ->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id');
        $approvers = DepartmentApprover::query()->with('user')->orderBy('sort_order')->get()->groupBy('department_id');

        $departments = Department::query()->with('head')->orderBy('path')->get()->map(fn (Department $d) => [
            'id' => $d->id,
            'parent_id' => $d->parent_id,
            'code' => $d->code,
            'name' => $d->name,
            'name_en' => $d->name_en,
            'display_name' => $d->displayName(),
            'depth' => $d->depth,
            'path' => $d->path,
            'cost_centre_code' => $d->cost_centre_code,
            'head_employee_id' => $d->head_employee_id,
            'head_name' => $d->head?->full_name,
            'is_active' => $d->is_active,
            'employee_count' => (int) ($counts[$d->id] ?? 0),
            'approvers' => collect($approvers->get($d->id, []))->groupBy('approval_type')
                ->map(fn ($rows) => $rows->map(fn (DepartmentApprover $a) => ['value' => $a->user_id, 'label' => $a->user?->name])->values())
                ->all(),
        ])->values();

        return Inertia::render('Core/Departments/Index', [
            'departments' => $departments,
            'employees' => Lookup::employees(),
            'users' => Lookup::members(),
            'approvalTypes' => array_map(fn (string $k) => ['value' => $k, 'label' => $registry->get($k)->label()], $registry->keys()),
            'canManage' => $request->user()->can('core.masters.manage'),
        ]);
    }

    public function store(Request $request, SaveDepartment $save): RedirectResponse
    {
        $this->requirePermission('core.masters.manage');
        $save->handle(null, $this->validated($request, null));

        return $this->done('common.saved');
    }

    public function update(Request $request, Department $department, SaveDepartment $save): RedirectResponse
    {
        $this->requirePermission('core.masters.manage');
        $save->handle($department, $this->validated($request, $department));

        return $this->done('common.saved');
    }

    public function approvers(Request $request, Department $department, SaveDepartmentApprovers $save): RedirectResponse
    {
        $this->requirePermission('core.masters.manage');
        $data = $request->validate([
            'approvers' => ['present', 'array'],
            'approvers.*' => ['array'],
            'approvers.*.*' => ['integer'],
        ]);
        $save->handle($department, $data['approvers']);

        return $this->done('common.saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Department $department): array
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:20', Rule::unique('departments', 'code')
                ->where('organisation_id', OrganisationContext::id())->ignore($department?->id)],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'cost_centre_code' => ['nullable', 'string', 'max:30'],
            'head_employee_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ]);

        if (! empty($data['head_employee_id'])) {
            abort_unless(Employee::query()->whereKey($data['head_employee_id'])->exists(), 422);
        }

        return $data;
    }
}
