<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Exports;

use App\Domain\Core\Enums\SalaryPaymentMode;
use App\Domain\Core\Models\Bank;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollFileKind;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\BankFileFormat;
use App\Domain\Payroll\Models\OrganisationBankAccount;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalarySlipLine;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Statutory\Files\FileFieldResolver;
use Illuminate\Support\Collection;

/**
 * Payroll exports (spec-04 §5.4, §5.6): bank file per paying account (generic
 * layout JSON), supplementary bank file for released withheld salaries,
 * cash/cheque list data and the summary CSV that replaces GL journals.
 */
final class PayrollExports
{
    public function __construct(private readonly PayrollFileStore $store) {}

    /** Bank file of a submitted or paid run: bank-transfer slips that are unpaid and not withheld. */
    public function bankFile(PayrollRun $run, OrganisationBankAccount $account): PayrollFile
    {
        if (! in_array($run->status, [PayrollRunStatus::Submitted, PayrollRunStatus::Paid], true)) {
            throw new PayrollRuleViolation('payroll.errors.bank_file_status');
        }
        $slips = $this->payable($run)->where('payment_status', PaymentStatus::Unpaid)->whereNull('released_at')->values();

        return $this->writeBank($run, $account, $slips, PayrollFileKind::Bank);
    }

    /** Released withheld salaries of a run, still unpaid (spec-04 §5.4). */
    public function supplementaryBankFile(PayrollRun $run, OrganisationBankAccount $account): PayrollFile
    {
        $slips = $this->payable($run)->where('payment_status', PaymentStatus::Unpaid)->whereNotNull('released_at')->values();

        return $this->writeBank($run, $account, $slips, PayrollFileKind::BankSupplementary);
    }

    /**
     * Cheque / cash list data (PDF rendered by the UI milestone).
     *
     * @return list<array{employee_no: string, full_name: string, payment_mode: string, net_pay: string}>
     */
    public function cashChequeList(PayrollRun $run): array
    {
        return SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', DocumentStatus::Submitted->value)
            ->whereIn('payment_mode', [SalaryPaymentMode::Cash->value, SalaryPaymentMode::Cheque->value])
            ->where('payment_status', PaymentStatus::Unpaid->value)->orderBy('employee_no')->get()
            ->map(fn (SalarySlip $s) => ['employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'payment_mode' => $s->payment_mode, 'net_pay' => (string) $s->net_pay])
            ->values()->all();
    }

    /**
     * @return Collection<int, SalarySlip>
     */
    private function payable(PayrollRun $run): Collection
    {
        return SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', DocumentStatus::Submitted->value)
            ->where('payment_mode', SalaryPaymentMode::BankTransfer->value)->orderBy('employee_no')->get();
    }

    /**
     * @param  Collection<int, SalarySlip>  $slips
     */
    private function writeBank(PayrollRun $run, OrganisationBankAccount $account, Collection $slips, PayrollFileKind $kind): PayrollFile
    {
        $format = $account->bank_file_format_id === null
            ? BankFileFormat::query()->where('code', 'CSV_GENERIK')->first()
            : BankFileFormat::query()->find($account->bank_file_format_id);
        if ($format === null) {
            throw new PayrollRuleViolation('payroll.errors.bank_format_missing');
        }

        $org = Organisation::query()->findOrFail(OrganisationContext::id());
        $layout = $format->layout;
        $delimiter = (string) ($layout['delimiter'] ?? ',');
        $total = '0';
        foreach ($slips as $slip) {
            $total = Num::add($total, (string) $slip->net_pay);
        }
        $totals = ['count' => $slips->count(), 'amount' => Num::money($total)];
        $banks = Bank::query()->pluck('code', 'id');

        $lines = [];
        foreach ((array) ($layout['header_rows'] ?? []) as $row) {
            $lines[] = $this->row((array) $row, $delimiter, ['org' => $org, 'account' => $account, 'payment_date' => $run->payment_date, 'totals' => $totals]);
        }
        foreach ($slips as $slip) {
            $ctx = ['org' => $org, 'slip' => $slip, 'account' => $account, 'payment_date' => $run->payment_date, 'totals' => $totals,
                'bank_code' => $slip->bank_id === null ? '' : (string) ($banks[$slip->bank_id] ?? '')];
            $lines[] = implode($delimiter, array_map(
                fn (array $f) => $this->csv(FileFieldResolver::format(FileFieldResolver::value((string) $f['source'], $ctx), $f['format'] ?? null), $delimiter),
                (array) ($layout['fields'] ?? []),
            ));
        }
        foreach ((array) ($layout['trailer'] ?? []) as $row) {
            $lines[] = $this->row((array) $row, $delimiter, ['org' => $org, 'account' => $account, 'payment_date' => $run->payment_date, 'totals' => $totals]);
        }

        $name = strtoupper($kind->value).'_'.$run->number.'_'.substr(preg_replace('/\D/', '', $account->account_no) ?? '', -4).'.csv';

        return $this->store->store($kind, $run->period, $name, implode("\r\n", $lines)."\r\n", [
            'run_id' => $run->id, 'account_id' => $account->id, 'format_code' => $format->code, 'format_version' => $format->version,
            'unverified' => $format->status !== 'verified', 'records' => $slips->count(), 'total' => $totals['amount'],
        ]);
    }

    /**
     * Header/trailer cells: "source:…" resolved, anything else literal.
     *
     * @param  list<mixed>  $cells
     * @param  array<string, mixed>  $ctx
     */
    private function row(array $cells, string $delimiter, array $ctx): string
    {
        return implode($delimiter, array_map(function ($cell) use ($ctx, $delimiter): string {
            $cell = (string) $cell;

            return $this->csv(str_starts_with($cell, 'source:') ? FileFieldResolver::value(substr($cell, 7), $ctx) : $cell, $delimiter);
        }, $cells));
    }

    private function csv(string $value, string $delimiter): string
    {
        return str_contains($value, $delimiter) || str_contains($value, '"') ? '"'.str_replace('"', '""', $value).'"' : $value;
    }

    /**
     * Summary CSV (replaces GL): cost centre × component, statutory payables, net by payment mode.
     * Σ earnings − Σ deductions = Σ net (spec-04 §5.6).
     */
    public function summaryCsv(PayrollRun $run): PayrollFile
    {
        $org = Organisation::query()->findOrFail(OrganisationContext::id());
        $slips = SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', '!=', DocumentStatus::Cancelled->value)->with(['lines', 'statutory'])->get();
        $departments = Department::query()->pluck('name', 'id');

        $groups = [];
        foreach ($slips as $slip) {
            foreach ($slip->lines as $line) {
                if ($line->kind === ComponentKind::Statistical) {
                    continue;
                }
                $key = ($slip->cost_centre_code ?? '').'|'.($slip->department_id ?? 0).'|'.$line->code;
                $groups[$key] ??= ['cost_centre' => (string) $slip->cost_centre_code, 'department' => (string) ($departments[$slip->department_id] ?? ''),
                    'code' => $line->code, 'name' => $line->name_ms, 'kind' => $line->kind->value, 'amount' => '0', 'employees' => []];
                $groups[$key]['amount'] = Num::add($groups[$key]['amount'], (string) $line->amount);
                $groups[$key]['employees'][$slip->employee_id] = true;
            }
        }
        ksort($groups);

        $rows = [['period', 'organisation', 'cost_centre_code', 'department', 'component_code', 'component_name', 'kind', 'amount', 'employee_count']];
        foreach ($groups as $g) {
            $rows[] = [$run->period->format('Y-m'), $org->short_code, $g['cost_centre'], $g['department'], $g['code'], $g['name'], $g['kind'], Num::money($g['amount']), (string) count($g['employees'])];
        }

        $sum = function (string $column) use ($slips): string {
            $total = '0';
            foreach ($slips as $slip) {
                $total = Num::add($total, (string) ($slip->statutory?->getAttribute($column) ?? '0'));
            }

            return Num::money($total);
        };
        $payables = [
            'KWSP' => Num::add($sum('epf_employee'), $sum('epf_employer')),
            'PERKESO' => Num::add($sum('socso_employee'), $sum('socso_employer')),
            'SKBBK' => $sum('skbbk_employee'),
            'SIP' => Num::add($sum('eis_employee'), $sum('eis_employer')),
            'PCB' => $sum('pcb_net'),
            'ZAKAT' => Num::add($sum('zakat_payroll')),
            'CP38' => $sum('cp38'),
            'HRDC' => $sum('hrdc_levy'),
        ];
        foreach ($payables as $label => $amount) {
            $rows[] = [$run->period->format('Y-m'), $org->short_code, '', '', 'PAYABLE_'.$label, $label, 'statutory_payable', Num::money($amount), ''];
        }
        foreach ($slips->groupBy('payment_mode') as $mode => $group) {
            $net = '0';
            foreach ($group as $slip) {
                $net = Num::add($net, (string) $slip->net_pay);
            }
            $rows[] = [$run->period->format('Y-m'), $org->short_code, '', '', 'NET_'.strtoupper((string) $mode), (string) $mode, 'net_pay', Num::money($net), (string) $group->count()];
        }

        $content = implode("\r\n", array_map(fn (array $r) => implode(',', array_map(fn ($c) => $this->csv((string) $c, ','), $r)), $rows))."\r\n";

        return $this->store->store(PayrollFileKind::SummaryCsv, $run->period, 'RINGKASAN_'.$run->number.'.csv', $content, [
            'run_id' => $run->id, 'records' => count($rows) - 1, 'total' => Num::money(Num::add(...$slips->map(fn (SalarySlip $s) => (string) $s->net_pay)->all())),
        ]);
    }

    /**
     * Salary Register data (spec-04 §6.7): fixed columns + one dynamic column per component
     * with a non-zero amount in the filtered set, ordered by kind then sort order; totals row.
     *
     * @param  array{period_from?: string|null, period_to?: string|null, payroll_run_id?: int|null, department_id?: int|null, branch_id?: int|null, payment_status?: string|null, include_employer?: bool}  $filters
     * @return array{columns: list<array{code: string, name: string, kind: string}>, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function register(array $filters): array
    {
        $slips = SalarySlip::query()->where('status', DocumentStatus::Submitted->value)
            ->when($filters['period_from'] ?? null, fn ($q, $v) => $q->whereDate('period', '>=', $v))
            ->when($filters['period_to'] ?? null, fn ($q, $v) => $q->whereDate('period', '<=', $v))
            ->when($filters['payroll_run_id'] ?? null, fn ($q, $v) => $q->where('payroll_run_id', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->with('lines')->orderBy('period')->orderBy('employee_no')->get();
        $includeEmployer = (bool) ($filters['include_employer'] ?? false);

        $columns = [];
        foreach ($slips as $slip) {
            foreach ($slip->lines as $line) {
                if ($line->kind === ComponentKind::Statistical || (! $includeEmployer && $line->kind === ComponentKind::EmployerContribution) || Num::isZero((string) $line->amount)) {
                    continue;
                }
                $columns[$line->code] ??= ['code' => $line->code, 'name' => $line->name_ms, 'kind' => $line->kind->value, 'order' => [$line->kind->displayOrder(), $line->sort_order]];
            }
        }
        uasort($columns, fn ($a, $b) => $a['order'] <=> $b['order']);

        $totals = ['gross_pay' => '0', 'total_deductions' => '0', 'net_pay' => '0'];
        foreach ($columns as $code => $c) {
            $totals[$code] = '0';
        }
        $rows = [];
        foreach ($slips as $slip) {
            $row = [
                'slip_id' => $slip->id, 'period' => $slip->period->format('Y-m'), 'employee_no' => $slip->employee_no, 'full_name' => $slip->full_name,
                'department_id' => $slip->department_id, 'designation_id' => $slip->designation_id, 'payment_days' => (string) $slip->payment_days,
                'gross_pay' => (string) $slip->gross_pay, 'total_deductions' => (string) $slip->total_deductions, 'net_pay' => (string) $slip->net_pay,
                'payment_status' => $slip->payment_status->value,
            ];
            $byCode = $slip->lines->groupBy('code');
            foreach ($columns as $code => $c) {
                $amount = '0';
                foreach ($byCode->get($code, collect()) as $line) {
                    /** @var SalarySlipLine $line */
                    $amount = Num::add($amount, (string) $line->amount);
                }
                $row[$code] = Num::money($amount);
                $totals[$code] = Num::add($totals[$code], $amount);
            }
            foreach (['gross_pay', 'total_deductions', 'net_pay'] as $k) {
                $totals[$k] = Num::add($totals[$k], (string) $row[$k]);
            }
            $rows[] = $row;
        }

        return [
            'columns' => array_values(array_map(fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind']], $columns)),
            'rows' => $rows,
            'totals' => array_map(fn (string $v) => Num::money($v), $totals),
        ];
    }
}
