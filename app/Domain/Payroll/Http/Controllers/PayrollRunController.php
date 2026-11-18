<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Payroll\Actions\CancelPayrollRun;
use App\Domain\Payroll\Actions\MarkPayrollRunPaid;
use App\Domain\Payroll\Actions\PayrollRunActions;
use App\Domain\Payroll\Actions\StartPayrollGeneration;
use App\Domain\Payroll\Actions\SubmitPayrollRun;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Exports\PayrollExports;
use App\Domain\Payroll\Exports\PayrollFileStore;
use App\Domain\Payroll\Http\PayrollPresenter;
use App\Domain\Payroll\Http\Requests\PayrollRunRequest;
use App\Domain\Payroll\Http\Requests\ReasonRequest;
use App\Domain\Payroll\Models\OrganisationBankAccount;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalarySlipLine;
use App\Domain\Payroll\Pdf\PayrollPdf;
use App\Domain\Payroll\Services\EligibleEmployees;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proses Gaji wizard (spec-04 §1.2, §5): list, create, review employees, queued
 * generation with polling, review, submit & lock (PA), payment and files (FIN),
 * cancel (PA). Every action is role-gated through PayrollRunPolicy.
 */
final class PayrollRunController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requirePermission('payroll.run.prepare', 'payroll.run.submit', 'payroll.run.mark-paid', 'payroll.slip.view-all');
        $runs = PayrollRun::query()->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('period')->orderByDesc('id')->paginate(30)->withQueryString();

        return Inertia::render('Payroll/Runs/Index', [
            'runs' => $runs->through(fn (PayrollRun $r) => PayrollPresenter::run($r)),
            'filters' => $request->only(['status']),
            'next_period' => $this->nextPeriod(),
            'options' => $this->filterOptions(),
            'can' => ['prepare' => $request->user()->can('payroll.run.prepare')],
        ]);
    }

    public function store(PayrollRunRequest $request, PayrollRunActions $actions): RedirectResponse
    {
        $this->requirePermission('payroll.run.prepare');
        $run = $actions->create($request->validated() + ['period' => (string) $request->input('period')], $request->user());

        return $this->done('common.saved', [], '/payroll/runs/'.$run->id);
    }

    public function show(Request $request, PayrollRun $run, EligibleEmployees $eligible, AttendanceForPayroll $attendance, UnpaidLeaveQuery $unpaid): Response
    {
        $this->authorize('view', $run);
        $user = $request->user();
        $slips = SalarySlip::query()->where('payroll_run_id', $run->id)->get()->keyBy('id');
        $rows = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->with('employee')->get();

        $preview = null;
        if ($run->status === PayrollRunStatus::Draft) {
            $result = $eligible->for($run);
            $range = new DateRange($run->period_start, $run->period_end);
            $incomplete = collect($attendance->completeness($run->organisation_id, $run->period_start, $run->period_end, $result['eligible']->pluck('id')->all()))
                ->mapWithKeys(fn ($c) => [$c->employeeId => ['unmarked' => count($c->unmarkedDates), 'needs_review' => count($c->needsReviewDates)]]);
            $preview = [
                'eligible' => $result['eligible']->map(fn ($e) => [
                    'id' => $e->id, 'employee_no' => $e->employee_no, 'full_name' => $e->full_name,
                    'warnings' => array_values(array_filter([
                        ($e->getAttribute('kwsp_no') ?? '') === '' ? 'missing_kwsp_no' : null,
                        ($e->getAttribute('bank_account_no') ?? '') === '' ? 'missing_bank' : null,
                        $e->status->value === 'suspended' ? 'suspended' : null,
                        $e->work_pass_expiry !== null && $e->work_pass_expiry->lessThan($run->period_end->addDays(60)) ? 'work_pass_expiring' : null,
                        isset($incomplete[$e->id]) ? 'attendance_incomplete' : null,
                        Num::isPositive($unpaid->pendingUnpaidDays($e, $range)) ? 'pending_unpaid_leave' : null,
                    ])),
                ])->all(),
                'no_assignment' => $result['no_assignment']->map(fn ($e) => ['id' => $e->id, 'employee_no' => $e->employee_no, 'full_name' => $e->full_name])->all(),
                'filtered_out' => $result['filtered_out'],
            ];
        }

        $totals = [];
        foreach (SalarySlipLine::query()->whereIn('salary_slip_id', $slips->keys())->get() as $line) {
            $totals[$line->code] ??= ['code' => $line->code, 'name' => $line->displayName(), 'kind' => $line->kind->value, 'amount' => '0'];
            $totals[$line->code]['amount'] = Num::money(Num::add($totals[$line->code]['amount'], (string) $line->amount));
        }

        return Inertia::render('Payroll/Runs/Show', [
            'run' => PayrollPresenter::run($run),
            'employees' => $rows->map(fn (PayrollRunEmployee $r) => PayrollPresenter::runEmployee($r, $r->salary_slip_id === null ? null : $slips->get($r->salary_slip_id)))->all(),
            'preview' => $preview,
            'component_totals' => array_values($totals),
            'rate_sets' => StatutoryRateSet::query()->whereIn('id', $run->rate_set_ids ?? [])->get()->map(fn ($s) => PayrollPresenter::rateSet($s))->all(),
            'files' => PayrollFile::query()->where('payroll_run_id', $run->id)->orderByDesc('id')->get()->map(fn ($f) => PayrollPresenter::file($f))->all(),
            'bank_accounts' => OrganisationBankAccount::query()->where('is_active', true)->get(['id', 'account_no', 'account_name'])->all(),
            'options' => $this->filterOptions(),
            'can' => [
                'prepare' => $user->can('prepare', $run), 'submit' => $user->can('submit', $run), 'mark_paid' => $user->can('markPaid', $run),
                'bank_file' => $user->can('bankFile', $run), 'cancel' => $user->can('cancel', $run),
            ],
        ]);
    }

    public function update(PayrollRunRequest $request, PayrollRun $run, PayrollRunActions $actions): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $actions->update($run, $request->validated());

        return $this->done('common.saved');
    }

    public function destroy(PayrollRun $run, CancelPayrollRun $cancel): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $cancel->delete($run);

        return $this->done('common.deleted', [], '/payroll/runs');
    }

    public function generate(Request $request, PayrollRun $run, StartPayrollGeneration $start): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $data = $request->validate(['employee_ids' => ['nullable', 'array'], 'employee_ids.*' => ['integer'], 'failed_only' => ['boolean']]);
        $ids = isset($data['employee_ids']) ? array_map('intval', $data['employee_ids']) : null;
        $start->handle($run, $request->user(), $ids, (bool) ($data['failed_only'] ?? false));

        return $this->done('payroll.messages.generation_started');
    }

    /** Polled every 2 s by the wizard (JSON). */
    public function progress(PayrollRun $run): JsonResponse
    {
        $this->authorize('view', $run);
        $counts = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->where('included', true)
            ->selectRaw('generation_status, count(*) as n')->groupBy('generation_status')->pluck('n', 'generation_status');

        return new JsonResponse([
            'status' => $run->status->value, 'processing_state' => $run->processing_state->value, 'processing_error' => $run->processing_error,
            'counts' => $counts, 'employee_count' => $run->employee_count, 'net_total' => (string) $run->net_total,
        ]);
    }

    public function exclude(ReasonRequest $request, PayrollRun $run, int $employee, PayrollRunActions $actions): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $actions->exclude($run, $employee, (string) $request->validated()['reason']);

        return $this->done('common.saved');
    }

    public function include(PayrollRun $run, int $employee, PayrollRunActions $actions): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $actions->include($run, $employee);

        return $this->done('common.saved');
    }

    public function overrideLwp(Request $request, PayrollRun $run, int $employee, PayrollRunActions $actions, StartPayrollGeneration $start): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $data = $request->validate(['days' => ['nullable', 'numeric', 'min:0'], 'reason' => ['required_with:days', 'nullable', 'string', 'max:255']]);
        $actions->overrideLwp($run, $employee, isset($data['days']) ? (string) $data['days'] : null, (string) ($data['reason'] ?? ''));
        $start->handle($run->refresh(), $request->user(), [$employee]);

        return $this->done('common.saved');
    }

    public function overridePcb(Request $request, PayrollRun $run, int $employee, PayrollRunActions $actions, StartPayrollGeneration $start): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0'], 'reason' => ['required_with:amount', 'nullable', 'string', 'max:255']]);
        $actions->overridePcb($run, $employee, isset($data['amount']) ? (string) $data['amount'] : null, (string) ($data['reason'] ?? ''));
        $start->handle($run->refresh(), $request->user(), [$employee]);

        return $this->done('common.saved');
    }

    public function discard(Request $request, PayrollRun $run, CancelPayrollRun $cancel): RedirectResponse
    {
        $this->authorize('prepare', $run);
        $cancel->discardSlips($run, $request->user());

        return $this->done('payroll.messages.discarded');
    }

    public function submit(Request $request, PayrollRun $run, SubmitPayrollRun $submit): RedirectResponse
    {
        $this->authorize('submit', $run);
        $submit->handle($run, $request->user(), $request->boolean('acknowledge_unverified'));

        return $this->done('payroll.messages.submitted');
    }

    public function markPaid(Request $request, PayrollRun $run, MarkPayrollRunPaid $paid): RedirectResponse
    {
        $this->authorize('markPaid', $run);
        $data = $request->validate(['paid_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:100']]);
        $paid->handle($run, $request->user(), CarbonImmutable::parse((string) $data['paid_on']), $data['reference'] ?? null);

        return $this->done('payroll.messages.paid');
    }

    public function cancel(ReasonRequest $request, PayrollRun $run, CancelPayrollRun $cancel): RedirectResponse
    {
        $this->authorize('cancel', $run);
        $cancel->cancel($run, $request->user(), (string) $request->validated()['reason']);

        return $this->done('payroll.messages.cancelled');
    }

    public function bankFile(Request $request, PayrollRun $run, PayrollExports $exports): RedirectResponse
    {
        $this->authorize('bankFile', $run);
        $data = $request->validate(['account_id' => ['required', 'integer'], 'supplementary' => ['boolean']]);
        $account = OrganisationBankAccount::query()->findOrFail((int) $data['account_id']);
        (bool) ($data['supplementary'] ?? false) ? $exports->supplementaryBankFile($run, $account) : $exports->bankFile($run, $account);

        return $this->done('payroll.messages.file_generated');
    }

    public function summaryCsv(PayrollRun $run, PayrollExports $exports): RedirectResponse
    {
        $this->requirePermission('payroll.run.mark-paid', 'payroll.run.prepare', 'payroll.run.submit');
        $exports->summaryCsv($run);

        return $this->done('payroll.messages.file_generated');
    }

    /** Cash / cheque payment list data (PDF by the UI milestone). */
    public function cashList(PayrollRun $run, PayrollExports $exports): JsonResponse
    {
        $this->authorize('bankFile', $run);

        return new JsonResponse(['rows' => $exports->cashChequeList($run)]);
    }

    /** "Senarai Bayaran Tunai/Cek" as PDF. */
    public function cashListPdf(PayrollRun $run, PayrollExports $exports, PayrollPdf $pdf): HttpResponse
    {
        $this->authorize('bankFile', $run);

        return response($pdf->cashList($run, $exports->cashChequeList($run)), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="senarai-bayaran-'.$run->number.'.pdf"',
        ]);
    }

    /**
     * Tempoh Gaji: one row per pay month (derived from the Proses Gaji runs), with the period
     * dates, the lock state and the run that covers it. A month is locked once its run is submitted.
     */
    public function periods(Request $request): Response
    {
        $this->requirePermission('payroll.run.prepare', 'payroll.run.submit', 'payroll.run.mark-paid', 'payroll.slip.view-all');
        $runs = PayrollRun::query()->where('status', '!=', PayrollRunStatus::Cancelled->value)->orderByDesc('period')->get()->groupBy(fn (PayrollRun $r) => $r->period->format('Y-m'));
        $next = CarbonImmutable::parse($this->nextPeriod().'-01');
        $oldest = $runs->keys()->sort()->first();
        $from = $oldest === null ? $next->subMonths(5) : CarbonImmutable::parse($oldest.'-01');

        $rows = [];
        for ($month = $next; $month->greaterThanOrEqualTo($from); $month = $month->subMonth()) {
            $key = $month->format('Y-m');
            /** @var PayrollRun|null $run */
            $run = $runs->get($key)?->first();
            $rows[] = [
                'period' => $key, 'start' => $month->startOfMonth()->toDateString(), 'end' => $month->endOfMonth()->toDateString(),
                'payment_date' => $run?->payment_date->toDateString() ?? $month->endOfMonth()->toDateString(),
                'locked' => $run !== null && in_array($run->status, [PayrollRunStatus::Submitted, PayrollRunStatus::Paid], true),
                'run' => $run === null ? null : ['id' => $run->id, 'number' => $run->number, 'status' => $run->status->value, 'status_label' => $run->status->label(),
                    'employee_count' => $run->employee_count, 'net_total' => (string) $run->net_total],
            ];
        }

        return Inertia::render('Payroll/Periods/Index', [
            'periods' => $rows, 'next_period' => $next->format('Y-m'), 'can' => ['prepare' => $request->user()->can('payroll.run.prepare')],
        ]);
    }

    public function download(PayrollFile $file, PayrollFileStore $store): StreamedResponse
    {
        $this->requirePermission('payroll.run.bank-file', 'payroll.run.prepare', 'payroll.run.submit', 'statutory.files.export');
        abort_unless(Storage::disk('local')->exists($file->file_path), 404);

        return Storage::disk('local')->download($file->file_path, $file->file_name);
    }

    private function nextPeriod(): string
    {
        $last = PayrollRun::query()->where('status', '!=', PayrollRunStatus::Cancelled->value)->max('period');

        return $last === null ? CarbonImmutable::today()->startOfMonth()->format('Y-m') : CarbonImmutable::parse((string) $last)->addMonth()->format('Y-m');
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'branches' => Branch::query()->orderBy('name')->get(['id', 'code', 'name'])->all(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'code', 'name'])->all(),
            'grades' => Grade::query()->orderBy('rank')->get(['id', 'code', 'name'])->all(),
            'employment_types' => EmploymentType::query()->orderBy('code')->get(['id', 'code', 'name'])->all(),
        ];
    }
}
