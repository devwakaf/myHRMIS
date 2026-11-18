<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Actions\MarkPayrollRunPaid;
use App\Domain\Payroll\Enums\EaFormStatus;
use App\Domain\Payroll\Http\PayrollPresenter;
use App\Domain\Payroll\Models\EaForm;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Pdf\PayrollPdf;
use App\Domain\Payroll\Services\SlipPublisher;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Senarai Slip Gaji, slip view, "Hantar Semula", and self-service Slip Gaji Saya /
 * Borang EA Saya (spec-04 §1.4). Another employee's slip or any draft → 404.
 */
final class SalarySlipController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requirePermission('payroll.slip.view-all');
        $slips = SalarySlip::query()
            ->when($request->query('period'), fn ($q, $v) => $q->whereDate('period', CarbonImmutable::parse($v.'-01')->toDateString()))
            ->when($request->query('payroll_run_id'), fn ($q, $v) => $q->where('payroll_run_id', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('payment_status'), fn ($q, $v) => $q->where('payment_status', $v))
            ->orderByDesc('period')->orderBy('employee_no')->paginate(50)->withQueryString();

        return Inertia::render('Payroll/Slips/Index', [
            'slips' => $slips->through(fn (SalarySlip $s) => [
                'id' => $s->id, 'number' => $s->number, 'period' => $s->period->format('Y-m'), 'employee_no' => $s->employee_no,
                'full_name' => $s->full_name, 'gross_pay' => (string) $s->gross_pay, 'total_deductions' => (string) $s->total_deductions,
                'net_pay' => (string) $s->net_pay, 'status' => $s->status->value, 'payment_status' => $s->payment_status->value,
            ]),
            'filters' => $request->only(['period', 'payroll_run_id', 'department_id', 'status', 'payment_status']),
        ]);
    }

    public function show(Request $request, SalarySlip $slip): Response
    {
        abort_unless($request->user()->can('view', $slip), 404);
        $slip->load(['lines', 'statutory']);
        $mask = PayrollSettings::current()->ic_display === 'masked' && ! $request->user()->can('core.employee.view-sensitive');

        return Inertia::render('Payroll/Slips/Show', [
            'slip' => PayrollPresenter::slip($slip, $request->user()->can('viewTrace', $slip), $mask),
            'can' => ['trace' => $request->user()->can('viewTrace', $slip), 'resend' => $request->user()->can('payroll.run.prepare'),
                'mark_paid' => $request->user()->can('payroll.run.mark-paid')],
        ]);
    }

    /** "Hantar e-mel slip": queues SalarySlipIssued with the cached PDF attached (HR / Payroll). */
    public function resend(SalarySlip $slip, SlipPublisher $publisher): RedirectResponse
    {
        $this->requirePermission('payroll.run.prepare', 'payroll.slip.view-all');
        abort_unless($slip->status === DocumentStatus::Submitted, 404);
        $publisher->notify(collect([$slip]));

        return $this->done('payroll.messages.slip_emailed');
    }

    /** Slip Gaji PDF for HR / Payroll (drafts are rendered fresh, with a ribbon). */
    public function pdf(Request $request, SalarySlip $slip, PayrollPdf $pdf): StreamedResponse
    {
        abort_unless($request->user()->can('view', $slip), 404);

        return $this->pdfResponse($pdf, $slip);
    }

    public function minePdf(Request $request, SalarySlip $slip, PayrollPdf $pdf): StreamedResponse
    {
        $employee = $this->ownEmployee($request);
        abort_unless($employee !== null && $slip->employee_id === $employee->id && app(SlipPublisher::class)->isPublished($slip), 404);

        return $this->pdfResponse($pdf, $slip);
    }

    private function pdfResponse(PayrollPdf $pdf, SalarySlip $slip): StreamedResponse
    {
        $path = $pdf->ensureSlip($slip);

        return Storage::disk('local')->download($path, $slip->number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Released withheld salary paid individually (spec-04 §5.4). */
    public function markPaid(Request $request, SalarySlip $slip, MarkPayrollRunPaid $paid): RedirectResponse
    {
        $this->requirePermission('payroll.run.mark-paid');
        $data = $request->validate(['paid_on' => ['required', 'date']]);
        $paid->markSlipPaid($slip, CarbonImmutable::parse((string) $data['paid_on']));

        return $this->done('payroll.messages.paid');
    }

    public function mine(Request $request, SlipPublisher $publisher): Response
    {
        $employee = $this->ownEmployee($request);
        $slips = $employee === null ? collect() : SalarySlip::query()->where('employee_id', $employee->id)
            ->where('status', DocumentStatus::Submitted->value)->orderByDesc('period')->get()
            ->filter(fn (SalarySlip $s) => $publisher->isPublished($s))->values();

        return Inertia::render('Payroll/Me/Payslips', [
            'slips' => $slips->map(fn (SalarySlip $s) => [
                'id' => $s->id, 'number' => $s->number, 'period' => $s->period->format('Y-m'), 'net_pay' => (string) $s->net_pay,
                'payment_status' => $s->payment_status->value, 'payment_status_label' => $s->payment_status->label(),
            ])->all(),
        ]);
    }

    public function mineShow(Request $request, SalarySlip $slip): Response
    {
        $employee = $this->ownEmployee($request);
        abort_unless($employee !== null && $slip->employee_id === $employee->id && app(SlipPublisher::class)->isPublished($slip), 404);
        $slip->load(['lines', 'statutory']);

        return Inertia::render('Payroll/Me/PayslipShow', ['slip' => PayrollPresenter::slip($slip, false, PayrollSettings::current()->ic_display === 'masked')]);
    }

    public function myEa(Request $request): Response
    {
        $employee = $this->ownEmployee($request);
        $forms = $employee === null ? collect() : EaForm::query()->where('employee_id', $employee->id)
            ->where('status', EaFormStatus::Published->value)->orderByDesc('tax_year')->get();

        return Inertia::render('Payroll/Me/Ea', ['forms' => $forms->map(fn (EaForm $f) => PayrollPresenter::ea($f))->all()]);
    }

    public function myEaPdf(Request $request, EaForm $form, PayrollPdf $pdf): \Illuminate\Http\Response
    {
        $employee = $this->ownEmployee($request);
        abort_unless($employee !== null && $form->employee_id === $employee->id && $form->status === EaFormStatus::Published, 404);

        return response($pdf->ea($form), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$form->serial_no.'.pdf"']);
    }

    private function ownEmployee(Request $request): ?Employee
    {
        return Employee::query()->where('user_id', $request->user()->id)->first();
    }
}
