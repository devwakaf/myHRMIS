<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Actions\AdditionalSalaryActions;
use App\Domain\Payroll\Actions\ArrearActions;
use App\Domain\Payroll\Actions\CorrectionActions;
use App\Domain\Payroll\Actions\TaxDeclarationActions;
use App\Domain\Payroll\Actions\WithholdingActions;
use App\Domain\Payroll\Enums\CorrectionType;
use App\Domain\Payroll\Http\PayrollPresenter;
use App\Domain\Payroll\Http\Requests\AdditionalSalaryRequest;
use App\Domain\Payroll\Http\Requests\DeclarationRequest;
use App\Domain\Payroll\Http\Requests\ReasonRequest;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\Arrear;
use App\Domain\Payroll\Models\PayrollCorrection;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryWithholding;
use App\Domain\Payroll\Models\SalaryWithholdingCycle;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Elaun/Potongan Tambahan, Tunggakan Gaji, Pembetulan Gaji, Tahan Gaji and
 * Deklarasi Cukai (spec-04 §1.3). Write: payroll.adjustments.manage (withholding:
 * payroll.withholding.manage; release: payroll.withholding.release).
 */
final class PayrollAdjustmentController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'employees' => Employee::query()->notLeft()->orderBy('full_name')->get(['id', 'employee_no', 'full_name'])->all(),
            'components' => SalaryComponent::query()->where('is_active', true)->get()
                ->filter(fn (SalaryComponent $c) => AdditionalSalaryActions::componentAllowed($c))
                ->map(fn (SalaryComponent $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->displayName(), 'kind' => $c->kind->value])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ additional salaries

    public function additional(Request $request): Response
    {
        $this->requirePermission('payroll.adjustments.manage', 'payroll.adjustments.view');
        $rows = AdditionalSalary::query()->with(['employee', 'component'])
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return Inertia::render('Payroll/AdditionalSalaries/Index', [
            'rows' => $rows->through(fn (AdditionalSalary $a) => PayrollPresenter::additional($a)),
            'filters' => $request->only(['employee_id', 'status']),
            'options' => $this->options(),
            'can' => ['manage' => $request->user()->can('payroll.adjustments.manage')],
        ]);
    }

    public function storeAdditional(AdditionalSalaryRequest $request, AdditionalSalaryActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $row = $actions->saveDraft(null, $request->validated());
        if ($request->boolean('submit')) {
            $actions->submit($row, $request->user());
        }

        return $this->done('common.saved');
    }

    public function updateAdditional(AdditionalSalaryRequest $request, AdditionalSalary $additional, AdditionalSalaryActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->saveDraft($additional, $request->validated());

        return $this->done('common.saved');
    }

    public function submitAdditional(Request $request, AdditionalSalary $additional, AdditionalSalaryActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->submit($additional, $request->user());

        return $this->done('common.saved');
    }

    public function cancelAdditional(ReasonRequest $request, AdditionalSalary $additional, AdditionalSalaryActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->cancel($additional, $request->user(), (string) $request->validated()['reason']);

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ arrears

    public function arrears(Request $request): Response
    {
        $this->requirePermission('payroll.adjustments.manage', 'payroll.adjustments.view');

        return Inertia::render('Payroll/Arrears/Index', [
            'rows' => Arrear::query()->with(['employee', 'lines'])->orderByDesc('id')->paginate(30)->through(fn (Arrear $a) => PayrollPresenter::arrear($a)),
            'assignments' => SalaryAssignment::query()->with('employee')->where('status', 'submitted')->orderByDesc('effective_from')->limit(200)->get()
                ->map(fn (SalaryAssignment $a) => ['id' => $a->id, 'number' => $a->number, 'employee' => $a->employee->full_name, 'effective_from' => $a->effective_from->toDateString(), 'base_salary' => (string) $a->base_salary])->all(),
            'can' => ['manage' => $request->user()->can('payroll.adjustments.manage')],
        ]);
    }

    /** "Jana Tunggakan": compute a draft arrear. */
    public function storeArrear(Request $request, ArrearActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $data = $request->validate([
            'salary_assignment_id' => ['required', 'integer'], 'from_period' => ['required', 'date'], 'to_period' => ['required', 'date'],
            'payout_period' => ['required', 'date'], 'notes' => ['nullable', 'string'],
        ]);
        $actions->compute(SalaryAssignment::query()->findOrFail((int) $data['salary_assignment_id']), CarbonImmutable::parse((string) $data['from_period']),
            CarbonImmutable::parse((string) $data['to_period']), CarbonImmutable::parse((string) $data['payout_period']), $data['notes'] ?? null);

        return $this->done('common.saved');
    }

    public function submitArrear(Request $request, Arrear $arrear, ArrearActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->submit($arrear, $request->user());

        return $this->done('common.saved');
    }

    public function cancelArrear(ReasonRequest $request, Arrear $arrear, ArrearActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->cancel($arrear, $request->user(), (string) $request->validated()['reason']);

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ corrections

    public function corrections(Request $request): Response
    {
        $this->requirePermission('payroll.adjustments.manage', 'payroll.adjustments.view');

        return Inertia::render('Payroll/Corrections/Index', [
            'rows' => PayrollCorrection::query()->with(['lines', 'employee', 'slip'])->orderByDesc('id')->paginate(30)->through(fn (PayrollCorrection $c) => PayrollPresenter::correction($c)),
            'slips' => SalarySlip::query()->where('status', DocumentStatus::Submitted->value)->orderByDesc('period')->orderBy('employee_no')->limit(300)->get()
                ->map(fn (SalarySlip $s) => ['id' => $s->id, 'number' => $s->number, 'period' => $s->period->format('Y-m'), 'employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'lwp_days' => (string) $s->lwp_days])->all(),
            'options' => $this->options(),
            'can' => ['manage' => $request->user()->can('payroll.adjustments.manage')],
        ]);
    }

    /** Refund preview for "Pulihkan Hari CTG" (JSON). */
    public function previewCorrection(Request $request, CorrectionActions $actions): JsonResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $data = $request->validate(['salary_slip_id' => ['required', 'integer'], 'days' => ['required', 'numeric', 'gt:0']]);

        return new JsonResponse(['lines' => $actions->refundLines(SalarySlip::query()->findOrFail((int) $data['salary_slip_id']), (string) $data['days'])]);
    }

    public function storeCorrection(Request $request, CorrectionActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $data = $request->validate([
            'salary_slip_id' => ['required', 'integer'], 'type' => ['required', 'in:lwp_reversal,amount'], 'payout_period' => ['required', 'date'],
            'reason' => ['required', 'string'], 'lwp_days_reversed' => ['nullable', 'numeric', 'gt:0'], 'lines' => ['nullable', 'array'],
            'lines.*.salary_component_id' => ['required', 'integer'], 'lines.*.amount' => ['required', 'numeric'], 'lines.*.basis' => ['nullable', 'string'],
        ]);
        /** @var list<array{salary_component_id: int, amount: string, basis?: string|null}> $lines */
        $lines = array_map(fn ($l) => ['salary_component_id' => (int) $l['salary_component_id'], 'amount' => (string) $l['amount'], 'basis' => $l['basis'] ?? null], array_values($data['lines'] ?? []));
        $actions->create(SalarySlip::query()->findOrFail((int) $data['salary_slip_id']), CorrectionType::from((string) $data['type']),
            CarbonImmutable::parse((string) $data['payout_period']), (string) $data['reason'],
            isset($data['lwp_days_reversed']) ? (string) $data['lwp_days_reversed'] : null, $lines);

        return $this->done('common.saved');
    }

    public function submitCorrection(Request $request, PayrollCorrection $correction, CorrectionActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->submit($correction, $request->user());

        return $this->done('common.saved');
    }

    public function cancelCorrection(ReasonRequest $request, PayrollCorrection $correction, CorrectionActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->cancel($correction, $request->user(), (string) $request->validated()['reason']);

        return $this->done('common.saved');
    }

    // ------------------------------------------------------------------ withholding

    public function withholdings(Request $request): Response
    {
        $this->requirePermission('payroll.withholding.manage', 'payroll.withholding.release', 'payroll.adjustments.view');

        return Inertia::render('Payroll/Withholdings/Index', [
            'rows' => SalaryWithholding::query()->with(['employee', 'cycleRows'])->orderByDesc('id')->paginate(30)
                ->through(fn (SalaryWithholding $w) => PayrollPresenter::withholding($w)),
            'options' => $this->options(),
            'can' => ['manage' => $request->user()->can('payroll.withholding.manage'), 'release' => $request->user()->can('payroll.withholding.release')],
        ]);
    }

    public function storeWithholding(Request $request, WithholdingActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.withholding.manage');
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'from_period' => ['required', 'date'], 'cycles' => ['nullable', 'integer', 'min:1', 'max:24'], 'reason' => ['required', 'string']]);
        $actions->create(['employee_id' => (int) $data['employee_id'], 'from_period' => (string) $data['from_period'],
            'cycles' => isset($data['cycles']) ? (int) $data['cycles'] : null, 'reason' => (string) $data['reason']], $request->user());

        return $this->done('common.saved');
    }

    public function cancelWithholding(ReasonRequest $request, SalaryWithholding $withholding, WithholdingActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.withholding.manage');
        $actions->cancel($withholding, $request->user(), (string) $request->validated()['reason']);

        return $this->done('common.saved');
    }

    public function releaseCycle(ReasonRequest $request, SalaryWithholdingCycle $cycle, WithholdingActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.withholding.release');
        abort_unless(SalaryWithholding::query()->whereKey($cycle->salary_withholding_id)->exists(), 404);
        $actions->release($cycle, $request->user(), (string) $request->validated()['reason']);

        return $this->done('payroll.messages.released');
    }

    // ------------------------------------------------------------------ tax declarations

    /**
     * TP1 relief codes (with the annual cap) of the latest Pelepasan & Pengecualian Cukai rate set.
     *
     * @return list<array{code: string, name: string, annual_cap: string|null}>
     */
    private function reliefCodes(): array
    {
        $set = StatutoryRateSet::query()->where('scheme', RateScheme::TaxReliefs->value)->where('status', '!=', 'draft')->orderByDesc('effective_from')->first();
        if ($set === null) {
            return [];
        }

        return DB::table('tp1_relief_codes')->where('rate_set_id', $set->id)->orderBy('id')->get()
            ->map(fn ($r) => ['code' => (string) $r->code, 'name' => app()->getLocale() === 'en' && $r->name_en !== null ? (string) $r->name_en : (string) $r->name_ms, 'annual_cap' => $r->annual_cap === null ? null : (string) $r->annual_cap])->all();
    }

    public function declarations(Request $request): Response
    {
        $this->requirePermission('payroll.adjustments.manage', 'payroll.adjustments.view');

        return Inertia::render('Payroll/Declarations/Index', [
            'rows' => TaxDeclaration::query()->with(['employee', 'lines'])->orderByDesc('id')->paginate(30)->through(fn (TaxDeclaration $d) => PayrollPresenter::declaration($d)),
            'options' => $this->options() + ['relief_codes' => $this->reliefCodes()],
            'can' => ['manage' => $request->user()->can('payroll.adjustments.manage')],
        ]);
    }

    public function storeDeclaration(DeclarationRequest $request, TaxDeclarationActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $row = $actions->save(null, $request->validated());
        if ($request->boolean('submit')) {
            $actions->submit($row, $request->user());
        }

        return $this->done('common.saved');
    }

    public function updateDeclaration(DeclarationRequest $request, TaxDeclaration $declaration, TaxDeclarationActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->save($declaration, $request->validated());

        return $this->done('common.saved');
    }

    public function submitDeclaration(Request $request, TaxDeclaration $declaration, TaxDeclarationActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->submit($declaration, $request->user());

        return $this->done('common.saved');
    }

    public function cancelDeclaration(ReasonRequest $request, TaxDeclaration $declaration, TaxDeclarationActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.adjustments.manage');
        $actions->cancel($declaration, $request->user(), (string) $request->validated()['reason']);

        return $this->done('common.saved');
    }
}
