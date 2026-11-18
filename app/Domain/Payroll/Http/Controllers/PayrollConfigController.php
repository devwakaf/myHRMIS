<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Actions\AssignmentActions;
use App\Domain\Payroll\Actions\ComponentActions;
use App\Domain\Payroll\Actions\StructureActions;
use App\Domain\Payroll\Enums\AssignmentReason;
use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\EaBox;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use App\Domain\Payroll\Enums\RoundingMode;
use App\Domain\Payroll\Enums\StatutoryKey;
use App\Domain\Payroll\Formula\ArrayScope;
use App\Domain\Payroll\Formula\Evaluator;
use App\Domain\Payroll\Formula\FormulaError;
use App\Domain\Payroll\Formula\FunctionRegistry;
use App\Domain\Payroll\Formula\Parser;
use App\Domain\Payroll\Formula\References;
use App\Domain\Payroll\Http\PayrollPresenter;
use App\Domain\Payroll\Http\Requests\AssignmentRequest;
use App\Domain\Payroll\Http\Requests\ComponentRequest;
use App\Domain\Payroll\Http\Requests\PayrollSettingsRequest;
use App\Domain\Payroll\Http\Requests\ReasonRequest;
use App\Domain\Payroll\Http\Requests\StructureRequest;
use App\Domain\Payroll\Models\BankFileFormat;
use App\Domain\Payroll\Models\OrganisationBankAccount;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Services\StructureDryRun;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainError;
use App\Domain\Statutory\Models\StatutoryFileFormat;
use App\Http\Controllers\Controller;
use BcMath\Number;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Komponen Gaji, Struktur Gaji, Penugasan Struktur Gaji, Tetapan Payroll and
 * paying bank accounts (spec-04 §1.1). Read: payroll.config.manage|view; write: manage.
 */
final class PayrollConfigController extends Controller
{
    // ------------------------------------------------------------------ components

    public function components(Request $request): Response
    {
        $this->requirePermission('payroll.config.manage', 'payroll.config.view');

        return Inertia::render('Payroll/Components/Index', [
            'components' => SalaryComponent::query()->orderBy('sort_order')->orderBy('code')->get()->map(fn ($c) => PayrollPresenter::component($c))->all(),
            'options' => self::componentOptions(),
            'can' => ['manage' => $request->user()->can('payroll.config.manage')],
        ]);
    }

    public function storeComponent(ComponentRequest $request, ComponentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $result = $actions->save(null, $request->validated());

        return $this->done('common.saved', $result['warnings']);
    }

    public function updateComponent(ComponentRequest $request, SalaryComponent $component, ComponentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $result = $actions->save($component, $request->validated());

        return $this->done('common.saved', $result['warnings']);
    }

    /** "Uji Formula": parse + evaluate against a sample bag (JSON). */
    public function testFormula(Request $request): JsonResponse
    {
        $this->requirePermission('payroll.config.manage', 'payroll.config.view');
        $data = $request->validate(['formula' => ['required', 'string', 'max:600'], 'values' => ['nullable', 'array']]);

        try {
            $ast = (new Parser)->parse((string) $data['formula']);
            $values = ['BASE' => new Number('3000'), 'GROSS' => new Number('3000'), 'PAYMENT_FRACTION' => new Number('1'), 'PAYMENT_DAYS' => new Number('26'),
                'LWP_DAYS' => new Number('0'), 'ABSENT_DAYS' => new Number('0'), 'CALENDAR_DAYS' => new Number('31'), 'WORKING_DAYS' => new Number('22'),
                'DIVISOR' => new Number('26'), 'PERIOD_MONTH' => new Number('1'), 'PERIOD_YEAR' => new Number('2026'), 'AGE' => new Number('35'),
                'YEARS_OF_SERVICE' => new Number('5'), 'GRADE' => '', 'DESIGNATION' => '', 'DEPARTMENT' => '', 'BRANCH' => '',
                'EMPLOYMENT_CATEGORY' => 'permanent', 'CITIZENSHIP' => 'citizen', 'IS_NEW_JOINER' => false, 'IS_LEAVER' => false];
            foreach ((array) ($data['values'] ?? []) as $code => $value) {
                if (is_numeric($value)) {
                    $values[strtoupper((string) $code)] = new Number((string) $value);
                }
            }
            $full = [];
            foreach (References::of($ast)->all() as $ref) {
                if (! FunctionRegistry::isVariable($ref)) {
                    $values[$ref] ??= new Number('0');
                }
                if (($values[$ref] ?? null) instanceof Number) {
                    $full[$ref] = $values[$ref];
                }
            }
            $result = (new Evaluator('UJI'))->evaluate($ast, new ArrayScope($values, $full));

            return new JsonResponse(['ok' => true, 'result' => is_bool($result) ? $result : (string) $result, 'references' => References::of($ast)->all(), 'ast' => $ast->toArray()]);
        } catch (FormulaError $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->userMessage(), 'code' => $e->errorCode], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function componentOptions(): array
    {
        return [
            'kinds' => ComponentKind::options(), 'methods' => CalculationMethod::options(), 'statutory_keys' => StatutoryKey::options(),
            'pcb_types' => PcbRemunerationType::options(), 'ea_boxes' => EaBox::options(), 'rounding_modes' => RoundingMode::options(),
            'functions' => array_keys(FunctionRegistry::FUNCTIONS), 'variables' => FunctionRegistry::variables(),
        ];
    }

    // ------------------------------------------------------------------ structures

    public function structures(Request $request): Response
    {
        $this->requirePermission('payroll.config.manage', 'payroll.config.view');

        return Inertia::render('Payroll/Structures/Index', [
            'structures' => SalaryStructure::query()->orderBy('code')->get()->map(fn ($s) => PayrollPresenter::structure($s))->all(),
            'can' => ['manage' => $request->user()->can('payroll.config.manage')],
        ]);
    }

    public function editStructure(Request $request, ?SalaryStructure $structure = null): Response
    {
        $this->requirePermission('payroll.config.manage', 'payroll.config.view');

        return Inertia::render('Payroll/Structures/Edit', [
            'structure' => $structure === null ? null : PayrollPresenter::structure($structure, true),
            'components' => SalaryComponent::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn ($c) => PayrollPresenter::component($c))->all(),
            'employees' => Employee::query()->notLeft()->orderBy('full_name')->limit(500)->get(['id', 'employee_no', 'full_name'])->all(),
            'options' => self::componentOptions(),
            'can' => ['manage' => $request->user()->can('payroll.config.manage')],
        ]);
    }

    public function storeStructure(StructureRequest $request, StructureActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $result = $actions->save(null, $request->validated(), $this->testEmployee($request));

        return $this->done('common.saved', $result['warnings'], '/payroll/structures/'.$result['structure']->id.'/edit')
            ->with('dry_run', PayrollPresenter::dryRun($result['dry_run']));
    }

    public function updateStructure(StructureRequest $request, SalaryStructure $structure, StructureActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $result = $actions->save($structure, $request->validated(), $this->testEmployee($request));

        return $this->done('common.saved', $result['warnings'])->with('dry_run', PayrollPresenter::dryRun($result['dry_run']));
    }

    public function copyStructure(Request $request, SalaryStructure $structure, StructureActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:30'], 'name_ms' => ['required', 'string', 'max:255']]);
        $copy = $actions->copy($structure, (string) $data['code'], (string) $data['name_ms']);

        return $this->done('common.saved', [], '/payroll/structures/'.$copy->id.'/edit');
    }

    /** Live validation + dry run without saving (JSON). */
    public function dryRun(StructureRequest $request, StructureActions $actions, StructureDryRun $dryRun): JsonResponse
    {
        $this->requirePermission('payroll.config.manage', 'payroll.config.view');
        try {
            [, $specs] = $actions->validateRows(array_values((array) ($request->validated()['rows'] ?? [])));
            $result = $dryRun->run($specs, $this->testEmployee($request));
        } catch (FormulaError $e) {
            return new JsonResponse(['ok' => false, 'errors' => ['rows' => [$e->userMessage()]]], 422);
        } catch (ValidationException $e) {
            return new JsonResponse(['ok' => false, 'errors' => $e->errors()], 422);
        }

        return new JsonResponse(['ok' => true, 'result' => PayrollPresenter::dryRun($result)]);
    }

    private function testEmployee(Request $request): ?Employee
    {
        $id = $request->input('test_employee_id');

        return $id === null ? null : Employee::query()->find((int) $id);
    }

    // ------------------------------------------------------------------ assignments

    public function assignments(Request $request): Response
    {
        $this->requirePermission('payroll.config.manage', 'payroll.config.view');
        $rows = SalaryAssignment::query()->with(['employee', 'structure'])
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('effective_from')->paginate(50)->withQueryString();

        return Inertia::render('Payroll/Assignments/Index', [
            'assignments' => $rows->through(fn (SalaryAssignment $a) => PayrollPresenter::assignment($a)),
            'filters' => $request->only(['employee_id', 'status']),
            'options' => [
                'structures' => SalaryStructure::query()->where('is_active', true)->orderBy('code')->with('rows.component')->get()->map(fn (SalaryStructure $s) => [
                    'id' => $s->id, 'code' => $s->code, 'name_ms' => $s->name_ms,
                    'components' => $s->rows->map(fn ($r) => ['id' => $r->salary_component_id, 'code' => $r->component->code, 'name' => $r->component->displayName(),
                        'calculation' => ($r->calculation_override ?? $r->component->calculation)->value])->values()->all(),
                ])->all(),
                'employees' => Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'employee_no', 'full_name'])->all(),
                'reasons' => AssignmentReason::options(),
            ],
            'can' => ['manage' => $request->user()->can('payroll.config.manage')],
        ]);
    }

    public function storeAssignment(AssignmentRequest $request, AssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $row = $actions->saveDraft(null, $request->validated());
        if ($request->boolean('submit')) {
            $actions->submit($row, $request->user());
        }

        return $this->done('common.saved');
    }

    /**
     * Bulk wizard ("Tetapkan Berkelompok"): one structure and effective date for many Kakitangan,
     * each with its own Gaji Pokok. Employees that fail validation are skipped and listed.
     */
    public function bulkAssignments(Request $request, AssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $data = $request->validate([
            'salary_structure_id' => ['required', 'integer'], 'effective_from' => ['required', 'date'],
            'reason' => ['nullable', 'in:new_hire,increment,promotion,correction,other'], 'submit' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'], 'items.*.employee_id' => ['required', 'integer'], 'items.*.base_salary' => ['required', 'numeric', 'min:0'],
        ]);
        $saved = 0;
        $failed = [];
        foreach ($data['items'] as $item) {
            $employee = Employee::query()->findOrFail((int) $item['employee_id']);
            try {
                DB::transaction(function () use ($actions, $request, $data, $item): void {
                    $row = $actions->saveDraft(null, [
                        'employee_id' => (int) $item['employee_id'], 'salary_structure_id' => (int) $data['salary_structure_id'], 'effective_from' => $data['effective_from'],
                        'base_salary' => (string) $item['base_salary'], 'reason' => $data['reason'] ?? 'new_hire',
                    ]);
                    if ($request->boolean('submit')) {
                        $actions->submit($row, $request->user());
                    }
                });
                $saved++;
            } catch (DomainError $e) {
                $failed[] = $employee->employee_no.': '.$e->userMessage();
            } catch (ValidationException $e) {
                $failed[] = $employee->employee_no.': '.$e->validator->errors()->first();
            }
        }

        return back()->with('success', __('payroll.messages.bulk_assigned', ['saved' => $saved, 'failed' => count($failed)]))->with('warnings', $failed);
    }

    public function updateAssignment(AssignmentRequest $request, SalaryAssignment $assignment, AssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $actions->saveDraft($assignment, $request->validated());
        if ($request->boolean('submit')) {
            $actions->submit($assignment->refresh(), $request->user());
        }

        return $this->done('common.saved');
    }

    public function submitAssignment(Request $request, SalaryAssignment $assignment, AssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $actions->submit($assignment, $request->user());

        return $this->done('common.saved');
    }

    public function cancelAssignment(ReasonRequest $request, SalaryAssignment $assignment, AssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $actions->cancel($assignment, $request->user(), (string) $request->validated()['reason']);

        return $this->done('common.saved');
    }

    public function prefillRehire(SalaryAssignment $assignment, AssignmentActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $actions->prefillRehireOpening($assignment);

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ settings

    public function settings(Request $request): Response
    {
        $this->requirePermission('payroll.config.manage', 'payroll.config.view');
        $settings = PayrollSettings::current();
        $options = [];
        foreach (PayrollSettings::OPTIONS as $field => $values) {
            $options[$field] = array_map(fn (string $v) => ['value' => $v, 'label' => (string) __("payroll.settings_options.$field.$v")], $values);
        }

        return Inertia::render('Payroll/Settings', [
            'settings' => $settings->toArray(),
            'daily_rate' => OrganisationContext::current()->only(['daily_rate_basis', 'daily_rate_divisor']),
            'bank_accounts' => OrganisationBankAccount::query()->with('bank')->get()->map(fn (OrganisationBankAccount $a) => $a->only([
                'id', 'bank_id', 'account_no', 'account_name', 'corporate_id', 'bank_file_format_id', 'is_default', 'is_active',
            ]) + ['bank' => $a->bank->name])->all(),
            'options' => $options + [
                'banks' => Bank::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])->all(),
                'bank_file_formats' => BankFileFormat::query()->get(['id', 'code', 'name', 'status'])->all(),
                'kwsp_file_formats' => StatutoryFileFormat::query()->where('scheme', 'kwsp')->get(['id', 'code', 'name', 'status'])->all(),
            ],
            'can' => ['manage' => $request->user()->can('payroll.config.manage')],
        ]);
    }

    public function updateSettings(PayrollSettingsRequest $request): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        PayrollSettings::current()->fill($request->validated())->save();

        return $this->done('common.saved');
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $account = new OrganisationBankAccount($this->bankAccountData($request));
        $account->save();

        return $this->done('common.saved');
    }

    public function updateBankAccount(Request $request, OrganisationBankAccount $account): RedirectResponse
    {
        $this->requirePermission('payroll.config.manage');
        $account->fill($this->bankAccountData($request))->save();

        return $this->done('common.saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function bankAccountData(Request $request): array
    {
        return $request->validate([
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'account_no' => ['required', 'string', 'max:30'],
            'account_name' => ['required', 'string', 'max:150'],
            'corporate_id' => ['nullable', 'string', 'max:40'],
            'bank_file_format_id' => ['nullable', 'integer', 'exists:bank_file_formats,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
