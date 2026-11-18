<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\MovementActions;
use App\Domain\Core\Enums\MovementType;
use App\Domain\Core\Http\Resources\EmployeeResource;
use App\Domain\Core\Http\Resources\Lookup;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeMovement;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pergerakan Kakitangan (B1.14, B3.9, B4).
 */
final class EmployeeMovementController extends Controller
{
    public function __construct(private readonly MovementActions $actions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', EmployeeMovement::class);
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');

        $movements = EmployeeMovement::query()->with('employee')
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (EmployeeMovement $m) => $this->row($m));

        return Inertia::render('Core/Movements/Index', [
            'movements' => $movements,
            'filters' => ['type' => $type, 'status' => $status],
            'options' => ['types' => MovementType::options(), 'statuses' => DocumentStatus::options()],
            'canCreate' => $request->user()->can('create', EmployeeMovement::class),
        ]);
    }

    public function create(Request $request, OrganisationMembership $membership): Response
    {
        $this->authorize('create', EmployeeMovement::class);
        $employeeId = $request->integer('employee_id');

        return Inertia::render('Core/Movements/Form', [
            'movement' => null,
            'employeeId' => $employeeId > 0 ? $employeeId : null,
            'employees' => $this->employeeCards(),
            'options' => $this->options($request, $membership),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', EmployeeMovement::class);
        $result = $this->actions->saveDraft(null, $this->validated($request));

        return $this->done('common.saved', $result['warnings'], '/employee-movements/'.$result['movement']->id);
    }

    public function show(Request $request, EmployeeMovement $movement): Response
    {
        $this->authorize('view', $movement);
        $movement->load('employee', 'targetOrganisation');

        return Inertia::render('Core/Movements/Show', [
            'movement' => $this->row($movement) + [
                'before_values' => $this->describe($movement->before_values ?? []),
                'after_values' => $this->describe($movement->after_values ?? []),
                'proposed' => $this->describe(array_filter([
                    'branch_id' => $movement->new_branch_id,
                    'department_id' => $movement->new_department_id,
                    'designation_id' => $movement->new_designation_id,
                    'grade_id' => $movement->new_grade_id,
                    'employment_type_id' => $movement->new_employment_type_id,
                    'reports_to_id' => $movement->new_reports_to_id,
                    'contract_end_date' => $movement->new_contract_end_date?->toDateString(),
                ])),
                'reason' => $movement->reason,
                'salary_note' => $movement->salary_note,
                'cancel_reason' => $movement->cancel_reason,
                'target_organisation' => $movement->targetOrganisation?->name,
                'target_employee_id' => $movement->target_employee_id,
            ],
            'can' => [
                'update' => $request->user()->can('update', $movement),
                'submit' => $request->user()->can('submit', $movement) && $movement->isDraft(),
                'cancel' => $request->user()->can('cancel', $movement) && $movement->status === DocumentStatus::Submitted,
            ],
        ]);
    }

    public function edit(Request $request, EmployeeMovement $movement, OrganisationMembership $membership): Response
    {
        $this->authorize('update', $movement);

        return Inertia::render('Core/Movements/Form', [
            'movement' => array_map([EmployeeResource::class, 'plain'], $movement->only(array_merge(['id', 'employee_id'], $movement->getFillable()))),
            'employeeId' => $movement->employee_id,
            'employees' => $this->employeeCards(),
            'options' => $this->options($request, $membership),
        ]);
    }

    public function update(Request $request, EmployeeMovement $movement): RedirectResponse
    {
        $this->authorize('update', $movement);
        $result = $this->actions->saveDraft($movement, $this->validated($request));

        return $this->done('common.saved', $result['warnings'], '/employee-movements/'.$movement->id);
    }

    public function destroy(EmployeeMovement $movement): RedirectResponse
    {
        $this->authorize('delete', $movement);
        $movement->delete();

        return $this->done('common.deleted', [], '/employee-movements');
    }

    public function submit(Request $request, EmployeeMovement $movement): RedirectResponse
    {
        $this->authorize('submit', $movement);
        $this->actions->submit($movement, $request->user());

        return $this->done('core.movement.submitted');
    }

    public function cancel(Request $request, EmployeeMovement $movement): RedirectResponse
    {
        $this->authorize('cancel', $movement);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->actions->cancel($movement, $request->user(), $data['reason']);

        return $this->done('common.cancelled');
    }

    public function cancelDuplicate(Request $request, EmployeeMovement $movement): RedirectResponse
    {
        $this->authorize('cancel', $movement);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $copy = $this->actions->cancelAndDuplicate($movement, $request->user(), $data['reason']);

        return $this->done('common.cancel_duplicated', [], '/employee-movements/'.$copy->id.'/edit');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(EmployeeMovement $m): array
    {
        return [
            'id' => $m->id,
            'number' => $m->number,
            'employee_id' => $m->employee_id,
            'employee' => $m->employee?->full_name,
            'employee_no' => $m->employee?->employee_no,
            'type' => $m->type->value,
            'type_label' => $m->type->label(),
            'effective_date' => $m->effective_date->toDateString(),
            'status' => $m->status->value,
            'status_label' => $m->status->label(),
            'applied_at' => $m->applied_at?->format('Y-m-d H:i'),
            'amended_from_id' => $m->amended_from_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{field: string, label: string, value: string|null}>
     */
    private function describe(array $values): array
    {
        $out = [];
        foreach ($values as $field => $value) {
            $label = match ($field) {
                'branch_id' => Branch::query()->find($value)?->displayName(),
                'department_id' => Department::query()->find($value)?->displayName(),
                'designation_id' => Designation::query()->find($value)?->displayName(),
                'grade_id' => Grade::query()->find($value)?->displayName(),
                'employment_type_id' => EmploymentType::query()->find($value)?->displayName(),
                'reports_to_id' => Employee::query()->find($value)?->full_name,
                default => $value === null ? null : (string) $value,
            };
            $out[] = ['field' => $field, 'label' => (string) __('validation.attributes.'.$field), 'value' => $label];
        }

        return $out;
    }

    /**
     * Current values shown in the "Semasa" column of the form.
     *
     * @return list<array<string, mixed>>
     */
    private function employeeCards(): array
    {
        return Employee::query()->where('status', '!=', 'left')->orderBy('full_name')->get()->map(fn (Employee $e) => [
            'value' => $e->id,
            'label' => $e->full_name.' ('.$e->employee_no.')',
            'current' => [
                'branch_id' => $e->branch_id,
                'department_id' => $e->department_id,
                'designation_id' => $e->designation_id,
                'grade_id' => $e->grade_id,
                'employment_type_id' => $e->employment_type_id,
                'reports_to_id' => $e->reports_to_id,
                'contract_end_date' => $e->contract_end_date?->toDateString(),
            ],
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Request $request, OrganisationMembership $membership): array
    {
        return array_merge(Lookup::employeeForm(), [
            'types' => MovementType::options(),
            'organisations' => $membership->organisationsFor($request->user())
                ->where('id', '!=', OrganisationContext::id())
                ->map(fn ($o) => ['value' => $o->id, 'label' => $o->name])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(MovementType::class)],
            'effective_date' => ['required', 'date'],
            'new_branch_id' => ['nullable', 'integer'],
            'new_department_id' => ['nullable', 'integer'],
            'new_designation_id' => ['nullable', 'integer'],
            'new_grade_id' => ['nullable', 'integer'],
            'new_employment_type_id' => ['nullable', 'integer'],
            'new_reports_to_id' => ['nullable', 'integer'],
            'new_contract_end_date' => ['nullable', 'date'],
            'target_organisation_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'salary_note' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
