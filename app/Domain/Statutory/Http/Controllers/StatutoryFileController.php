<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Actions\EaFormActions;
use App\Domain\Payroll\Enums\PayrollFileKind;
use App\Domain\Payroll\Enums\RemittanceScheme;
use App\Domain\Payroll\Http\PayrollPresenter;
use App\Domain\Payroll\Models\EaForm;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\StatutoryRemittance;
use App\Domain\Payroll\Pdf\PayrollPdf;
use App\Domain\Statutory\Files\StatutoryFiles;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Fail Statutori, Bayaran Statutori and Borang EA (spec-04 §6). Export:
 * statutory.files.export; remittances: statutory.remittances.manage; EA publish:
 * statutory.ea.publish.
 */
final class StatutoryFileController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requirePermission('statutory.files.export');
        $period = CarbonImmutable::parse(((string) $request->query('period', CarbonImmutable::today()->subMonth()->format('Y-m'))).'-01');

        return Inertia::render('Statutory/Files/Index', [
            'period' => $period->format('Y-m'),
            'files' => PayrollFile::query()->whereDate('period', $period->toDateString())
                ->whereIn('kind', [PayrollFileKind::Kwsp->value, PayrollFileKind::Perkeso->value, PayrollFileKind::Cp39->value])
                ->orderByDesc('id')->get()->map(fn ($f) => PayrollPresenter::file($f))->all(),
            'remittances' => StatutoryRemittance::query()->whereDate('period', $period->toDateString())->get()->toArray(),
            'can' => ['export' => $request->user()->can('statutory.files.export'), 'remittances' => $request->user()->can('statutory.remittances.manage')],
        ]);
    }

    public function generate(Request $request, string $kind, StatutoryFiles $files): RedirectResponse
    {
        $this->requirePermission('statutory.files.export');
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $period = CarbonImmutable::parse($data['period'].'-01');
        match ($kind) {
            'perkeso' => $files->perkeso($period),
            'cp39' => $files->cp39($period),
            'kwsp' => $files->kwsp($period),
            default => abort(404),
        };

        return back()->with('success', __('payroll.messages.file_generated'));
    }

    public function markSubmitted(Request $request, PayrollFile $file): RedirectResponse
    {
        $this->requirePermission('statutory.remittances.manage');
        $data = $request->validate(['external_ref' => ['nullable', 'string', 'max:100']]);
        $file->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $request->user()->id, 'external_ref' => $data['external_ref'] ?? null])->save();

        return back()->with('success', __('statutory.messages.file_submitted'));
    }

    public function remittances(Request $request): Response
    {
        $this->requirePermission('statutory.remittances.manage', 'statutory.files.export');

        return Inertia::render('Statutory/Remittances/Index', [
            'remittances' => StatutoryRemittance::query()->orderByDesc('period')->paginate(50)->through(fn (StatutoryRemittance $r) => $r->toArray()),
            'options' => ['schemes' => RemittanceScheme::options()],
            'can' => ['manage' => $request->user()->can('statutory.remittances.manage')],
        ]);
    }

    public function storeRemittance(Request $request): RedirectResponse
    {
        $this->requirePermission('statutory.remittances.manage');
        $data = $request->validate([
            'scheme' => ['required', 'in:kwsp,perkeso,pcb,hrdc,zakat'], 'period' => ['required', 'date_format:Y-m'], 'amount' => ['required', 'numeric', 'min:0'],
            'paid_on' => ['required', 'date'], 'receipt_no' => ['nullable', 'string', 'max:60'], 'payroll_file_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string'],
        ]);
        $row = new StatutoryRemittance(['period' => $data['period'].'-01'] + $data);
        $row->save();

        return back()->with('success', __('statutory.messages.remittance_saved'));
    }

    public function ea(Request $request): Response
    {
        $this->requirePermission('statutory.files.export', 'statutory.ea.publish');
        $year = (int) $request->query('year', (string) (CarbonImmutable::today()->year - 1));

        return Inertia::render('Statutory/Ea/Index', [
            'year' => $year,
            'forms' => EaForm::query()->where('tax_year', $year)->with('employee')->orderBy('employee_id')->get()
                ->map(fn (EaForm $f) => PayrollPresenter::ea($f) + ['employee' => $f->employee->full_name])->all(),
            'can' => ['generate' => $request->user()->can('statutory.files.export'), 'publish' => $request->user()->can('statutory.ea.publish')],
        ]);
    }

    public function generateEa(Request $request, EaFormActions $actions): RedirectResponse
    {
        $this->requirePermission('statutory.files.export');
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $forms = $actions->generate((int) $data['year']);

        return back()->with('success', __('payroll.messages.ea_generated', ['count' => count($forms)]));
    }

    public function publishEa(Request $request, EaForm $form, EaFormActions $actions): RedirectResponse
    {
        $this->requirePermission('statutory.ea.publish');
        $actions->publish($form, $request->user());

        return back()->with('success', __('common.saved'));
    }

    /** Borang EA as PDF (draft versions carry a "belum diterbitkan" notice). */
    public function eaPdf(EaForm $form, PayrollPdf $pdf): HttpResponse
    {
        $this->requirePermission('statutory.files.export', 'statutory.ea.publish');

        return response($pdf->ea($form), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$form->serial_no.'.pdf"']);
    }

    /** PCB 2(II) as PDF for one employee and year. */
    public function pcb2iiPdf(Request $request, int $employee, EaFormActions $actions, PayrollPdf $pdf): HttpResponse
    {
        $this->requirePermission('statutory.files.export');
        $year = (int) $request->query('year', (string) CarbonImmutable::today()->year);
        $person = Employee::query()->findOrFail($employee);

        return response($pdf->pcb2ii($actions->pcb2ii($employee, $year), [
            'employee_no' => $person->employee_no, 'full_name' => $person->full_name, 'ic_no' => null, 'tin' => $person->taxProfile?->tin,
        ]), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="pcb2ii-'.$person->employee_no.'-'.$year.'.pdf"']);
    }

    /** PCB 2(II) data for one employee and year (JSON). */
    public function pcb2ii(Request $request, int $employee, EaFormActions $actions): JsonResponse
    {
        $this->requirePermission('statutory.files.export');

        return new JsonResponse($actions->pcb2ii($employee, (int) $request->query('year', (string) CarbonImmutable::today()->year)));
    }
}
