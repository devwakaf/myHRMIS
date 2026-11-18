<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\HolidayAssignmentActions;
use App\Domain\Core\Enums\AssignableType;
use App\Domain\Core\Http\Resources\Lookup;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penetapan Senarai Cuti (B1.13, B3.8, B4).
 */
final class HolidayAssignmentController extends Controller
{
    public function __construct(private readonly HolidayAssignmentActions $actions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', HolidayListAssignment::class);
        $level = (string) $request->query('level', '');
        $status = (string) $request->query('status', '');

        $assignments = HolidayListAssignment::query()
            ->with('holidayList')
            ->when($level !== '', fn ($q) => $q->where('assignable_type', $level))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (HolidayListAssignment $a) => [
                'id' => $a->id,
                'number' => $a->number,
                'holiday_list_id' => $a->holiday_list_id,
                'holiday_list' => $a->holidayList?->displayName(),
                'assignable_type' => $a->assignable_type->value,
                'assignable_type_label' => $a->assignable_type->label(),
                'assignable_id' => $a->assignable_id,
                'assignable' => $a->assignableLabel(),
                'effective_from' => $a->effective_from->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'status' => $a->status->value,
                'status_label' => $a->status->label(),
                'remarks' => $a->remarks,
                'cancel_reason' => $a->cancel_reason,
                'amended_from_id' => $a->amended_from_id,
            ]);

        return Inertia::render('Core/HolidayAssignments/Index', [
            'assignments' => $assignments,
            'filters' => ['level' => $level, 'status' => $status],
            'options' => [
                'levels' => AssignableType::options(),
                'statuses' => DocumentStatus::options(),
                'lists' => Lookup::holidayLists(),
                'branches' => Lookup::masters(Branch::class, true),
                'departments' => Lookup::masters(Department::class, true),
                'employment_types' => Lookup::masters(EmploymentType::class, true),
                'employees' => Lookup::employees(),
            ],
            'can' => [
                'manage' => $request->user()->can('core.holiday.manage'),
                'cancel' => $request->user()->can('core.holiday-assignment.cancel'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', HolidayListAssignment::class);
        $assignment = $this->actions->saveDraft(null, $this->validated($request));

        if ($request->boolean('submit')) {
            $this->actions->submit($assignment, $request->user());
        }

        return $this->done('common.saved');
    }

    public function update(Request $request, HolidayListAssignment $assignment): RedirectResponse
    {
        $this->authorize('update', $assignment);
        $this->actions->saveDraft($assignment, $this->validated($request));

        return $this->done('common.saved');
    }

    public function destroy(HolidayListAssignment $assignment): RedirectResponse
    {
        $this->authorize('delete', $assignment);
        $assignment->delete();

        return $this->done('common.deleted');
    }

    public function submit(Request $request, HolidayListAssignment $assignment): RedirectResponse
    {
        $this->authorize('submit', $assignment);
        $this->actions->submit($assignment, $request->user());

        return $this->done('common.submitted');
    }

    public function cancel(Request $request, HolidayListAssignment $assignment): RedirectResponse
    {
        $this->authorize('cancel', $assignment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->actions->cancel($assignment, $request->user(), $data['reason']);

        return $this->done('common.cancelled');
    }

    public function cancelDuplicate(Request $request, HolidayListAssignment $assignment): RedirectResponse
    {
        $this->authorize('cancel', $assignment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->actions->cancelAndDuplicate($assignment, $request->user(), $data['reason']);

        return $this->done('common.cancel_duplicated');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $this->authorize('create', HolidayListAssignment::class);
        $data = $request->validate([
            'holiday_list_id' => ['required', 'integer'],
            'effective_from' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employment_type_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $list = HolidayList::query()->findOrFail($data['holiday_list_id']);
        $count = $this->actions->bulkAssign($list, $data['effective_from'], $data, $request->user(), $data['remarks'] ?? null);

        return back()->with('success', __('core.holiday_assignment.bulk_done', ['count' => $count]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'holiday_list_id' => ['required', 'integer'],
            'assignable_type' => ['required', Rule::enum(AssignableType::class)],
            'assignable_id' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['assignable_type'] === AssignableType::Organisation->value) {
            $data['assignable_id'] = OrganisationContext::id();
        }

        return $data;
    }
}
