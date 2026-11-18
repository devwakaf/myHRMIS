<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\CreateEmployee;
use App\Domain\Core\Actions\UpdateEmployee;
use App\Domain\Core\Enums\BloodType;
use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\EmergencyRelationship;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\IdentityType;
use App\Domain\Core\Enums\Lindung24Status;
use App\Domain\Core\Enums\MaritalStatus;
use App\Domain\Core\Enums\MovementType;
use App\Domain\Core\Enums\MtdScheme;
use App\Domain\Core\Enums\QualificationLevel;
use App\Domain\Core\Enums\Race;
use App\Domain\Core\Enums\Religion;
use App\Domain\Core\Enums\SalaryPaymentMode;
use App\Domain\Core\Enums\SeparationReason;
use App\Domain\Core\Enums\TaxResidency;
use App\Domain\Core\Enums\WorkPassType;
use App\Domain\Core\Http\Requests\EmployeeRequest;
use App\Domain\Core\Http\Resources\EmployeeResource;
use App\Domain\Core\Http\Resources\Lookup;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeDocument;
use App\Domain\Core\Models\EmployeeWorkHistory;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Services\EmployeeQuery;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Core\Services\PcbCategorySuggester;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Audit\Activity;
use App\Domain\Shared\Support\DomainError;
use App\Domain\Shared\Support\Enums\Gender;
use App\Domain\Shared\Support\Enums\MalaysianState;
use App\Domain\Shared\Support\SpreadsheetExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Senarai / Tambah / Butiran / Edit Kakitangan (B4).
 */
final class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeQuery $query) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);
        $sensitive = $request->user()->can('core.employee.view-sensitive');
        $filters = $request->only(EmployeeQuery::FILTERS);

        $employees = $this->query->build($filters, $sensitive)
            ->orderBy('full_name')
            ->paginate((int) min(100, max(10, $request->integer('per_page', 25))))
            ->withQueryString()
            ->through(fn (Employee $e) => $this->query->listRow($e, $sensitive));

        return Inertia::render('Core/Employees/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'options' => array_merge(Lookup::employeeForm(), [
                'statuses' => EmployeeStatus::options(),
                'citizenships' => CitizenshipStatus::options(),
            ]),
            'can' => [
                'create' => $request->user()->can('create', Employee::class),
                'sensitive' => $sensitive,
            ],
        ]);
    }

    public function create(Request $request, ApprovalTypeRegistry $registry): Response
    {
        $this->authorize('create', Employee::class);

        return Inertia::render('Core/Employees/Form', [
            'employee' => null,
            'options' => $this->formOptions($registry),
            'settings' => $this->settingsProps(),
        ]);
    }

    public function store(EmployeeRequest $request, CreateEmployee $create): RedirectResponse
    {
        $this->authorize('create', Employee::class);
        $result = $create->handle($request->validated() + ['emergency_contacts' => $request->input('emergency_contacts', [])]);

        return $this->done('core.employee.created', $result['warnings'], '/employees/'.$result['employee']->id);
    }

    public function show(Request $request, Employee $employee, HolidayResolver $resolver, ApprovalTypeRegistry $registry): Response
    {
        $this->authorize('view', $employee);
        $user = $request->user();
        $employee->load(['branch', 'department', 'designation', 'grade', 'employmentType', 'reportsTo', 'user', 'bank', 'taxProfile', 'emergencyContacts']);

        $holidayList = null;
        try {
            $list = $resolver->listFor($employee, today());
            $holidayList = ['id' => $list->id, 'name' => $list->displayName(), 'fallback' => $list->resolvedByFallback];
        } catch (DomainError) {
            $holidayList = null;
        }

        $canViewDocs = $user->can('viewDocuments', $employee);
        $hr = $user->can('core.employee.view');

        return Inertia::render('Core/Employees/Show', [
            'employee' => (new EmployeeResource($employee))->resolve($request),
            'emergencyContacts' => $employee->emergencyContacts->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'relationship' => $c->relationship->value,
                'relationship_label' => $c->relationship->label(), 'phone' => $c->phone, 'alt_phone' => $c->alt_phone,
                'address' => $c->address, 'is_primary' => $c->is_primary,
            ])->values(),
            'documents' => $canViewDocs ? EmployeeDocument::query()->where('employee_id', $employee->id)->with('documentType')
                ->latest()->get()->map(fn (EmployeeDocument $d) => [
                    'id' => $d->id, 'title' => $d->title, 'type' => $d->documentType?->displayName(),
                    'original_name' => $d->original_name, 'size_bytes' => $d->size_bytes,
                    'issued_on' => $d->issued_on?->toDateString(), 'expires_on' => $d->expires_on?->toDateString(),
                    'uploaded_at' => $d->created_at?->format('Y-m-d H:i'),
                ])->values() : [],
            'workHistory' => $hr || $employee->user_id === $user->id
                ? EmployeeWorkHistory::query()->where('employee_id', $employee->id)
                    ->with(['branch', 'department', 'designation', 'grade', 'employmentType'])->orderByDesc('from_date')->get()
                    ->map(fn (EmployeeWorkHistory $h) => [
                        'id' => $h->id, 'from_date' => $h->from_date->toDateString(), 'to_date' => $h->to_date?->toDateString(),
                        'branch' => $h->branch?->displayName(), 'department' => $h->department?->displayName(),
                        'designation' => $h->designation?->displayName(), 'grade' => $h->grade?->displayName(),
                        'employment_type' => $h->employmentType?->displayName(), 'source' => $h->source_type->label(),
                    ])->values()
                : [],
            'movements' => $hr ? $employee->movements()->get()->map(fn ($m) => [
                'id' => $m->id, 'number' => $m->number, 'type' => $m->type->label(), 'effective_date' => $m->effective_date->toDateString(),
                'status' => $m->status->value, 'status_label' => $m->status->label(), 'applied' => $m->isApplied(),
            ])->values() : [],
            'qualifications' => $employee->qualifications()->get()->map(fn ($q) => [
                'id' => $q->id, 'level' => $q->level->label(), 'institution' => $q->institution, 'field' => $q->field,
                'year_completed' => $q->year_completed, 'grade_result' => $q->grade_result,
            ])->values(),
            'previousEmployments' => $employee->previousEmployments()->get()->map(fn ($p) => [
                'id' => $p->id, 'employer_name' => $p->employer_name, 'position' => $p->position,
                'from_date' => $p->from_date?->toDateString(), 'to_date' => $p->to_date?->toDateString(),
                'reason_left' => $p->reason_left,
                'last_salary' => $user->can('viewCompensation', $employee) ? $p->last_salary?->format() : null,
            ])->values(),
            'approvers' => $employee->approvers()->with('user')->get()
                ->mapWithKeys(fn ($a) => [$a->approval_type => ['value' => $a->user_id, 'label' => $a->user?->name]]),
            'lindung24' => $user->can('viewSensitive', $employee) ? $employee->lindung24Elections()->get()->map(fn ($e) => [
                'id' => $e->id, 'status' => $e->status->label(), 'effective_from' => $e->effective_from->toDateString(),
                'effective_to' => $e->effective_to?->toDateString(), 'note' => $e->note,
            ])->values() : [],
            'audit' => $user->can('viewAudit', $employee) ? Activity::query()
                ->where('subject_type', $employee->getMorphClass())->where('subject_id', $employee->id)
                ->with('causer')->latest('id')->limit(100)->get()->map(fn (Activity $a) => [
                    'id' => $a->id, 'event' => $a->event, 'description' => $a->description,
                    'causer' => $a->causer?->getAttribute('name'), 'properties' => $a->properties,
                    'created_at' => $a->created_at?->format('Y-m-d H:i'),
                ])->values() : [],
            'holidayList' => $holidayList,
            'directReports' => Employee::query()->where('reports_to_id', $employee->id)
                ->where('status', '!=', EmployeeStatus::Left->value)->count(),
            'options' => $hr ? $this->formOptions($registry) : null,
            'can' => [
                'update' => $user->can('update', $employee),
                'correct' => $user->can('correct', $employee),
                'separate' => $user->can('separate', $employee),
                'manageDocuments' => $user->can('manageDocuments', $employee),
                'viewDocuments' => $canViewDocs,
                'movement' => $user->can('core.movement.create'),
                'createUser' => $user->can('update', $employee) && $employee->user_id === null,
                'rehire' => $user->can('create', Employee::class) && $employee->isLeft(),
                'sensitive' => $user->can('viewSensitive', $employee),
                'audit' => $user->can('viewAudit', $employee),
                'leave' => $employee->user_id === $user->id || $user->can('leave.application.view-all'),
                'attendance' => $user->can('attendance.manage') || $user->can('attendance.view-all'),
                'attendanceManage' => $user->can('attendance.manage'),
                'payroll' => $user->can('payroll.slip.view-all'),
                'hr' => $hr,
            ],
        ]);
    }

    public function edit(Request $request, Employee $employee, ApprovalTypeRegistry $registry): Response
    {
        $this->authorize('update', $employee);
        $employee->load(['taxProfile', 'emergencyContacts']);

        return Inertia::render('Core/Employees/Form', [
            'employee' => (new EmployeeResource($employee))->resolve($request),
            'emergencyContacts' => $employee->emergencyContacts->map(fn ($c) => $c->only(['name', 'phone', 'alt_phone', 'address', 'is_primary']) + ['relationship' => $c->relationship->value])->values(),
            'approvers' => $employee->approvers()->pluck('user_id', 'approval_type'),
            'options' => $this->formOptions($registry),
            'settings' => $this->settingsProps(),
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee, UpdateEmployee $update): RedirectResponse
    {
        $this->authorize('update', $employee);
        $warnings = $update->handle($employee, $request->validated());

        return $this->done('common.saved', $warnings, '/employees/'.$employee->id);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Employee::class);
        $sensitive = $request->user()->can('core.employee.view-sensitive');
        $rows = $this->query->build($request->only(EmployeeQuery::FILTERS), $sensitive)->orderBy('full_name')->get();

        $columns = ['employee_no', 'full_name', 'status_label', 'branch', 'department', 'designation', 'grade', 'employment_type', 'citizenship', 'date_of_joining', 'mobile_phone', 'work_email'];
        if ($sensitive) {
            array_splice($columns, 2, 0, ['ic_no', 'passport_no']);
        }

        $headers = array_map(fn ($c) => (string) __('core.employee.columns.'.$c), $columns);
        $data = $rows->map(function (Employee $e) use ($columns, $sensitive) {
            $row = $this->query->listRow($e, $sensitive);

            return array_map(fn ($c) => $row[$c] ?? null, $columns);
        });

        return SpreadsheetExport::download('kakitangan', $headers, $data, (string) $request->query('format', 'xlsx'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(ApprovalTypeRegistry $registry): array
    {
        return array_merge(Lookup::employeeForm(), [
            'identity_types' => IdentityType::options(),
            'genders' => Gender::options(),
            'citizenships' => CitizenshipStatus::options(),
            'races' => Race::options(),
            'religions' => Religion::options(),
            'marital_statuses' => MaritalStatus::options(),
            'blood_types' => BloodType::options(),
            'states' => MalaysianState::options(),
            'work_pass_types' => WorkPassType::options(),
            'payment_modes' => SalaryPaymentMode::options(),
            'tax_residencies' => TaxResidency::options(),
            'mtd_schemes' => MtdScheme::options(),
            'relationships' => EmergencyRelationship::options(),
            'separation_reasons' => SeparationReason::options(),
            'qualification_levels' => QualificationLevel::options(),
            'movement_types' => MovementType::options(),
            'lindung24_statuses' => Lindung24Status::options(),
            'document_types' => Lookup::documentTypes(),
            'approval_types' => array_map(fn (string $k) => ['value' => $k, 'label' => $registry->get($k)->label()], $registry->keys()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsProps(): array
    {
        $settings = HrSettings::current();

        return [
            'employee_no_mode' => $settings->employee_no_mode->value,
            'min_employment_age' => $settings->min_employment_age,
            'default_probation_months' => $settings->default_probation_months,
            'pcb_rules' => ['single' => app(PcbCategorySuggester::class)->suggest(MaritalStatus::Single, null)],
        ];
    }
}
