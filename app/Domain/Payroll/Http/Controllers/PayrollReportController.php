<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Payroll\Exports\PayrollExports;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Shared\Support\SpreadsheetExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daftar Gaji / Salary Register (spec-04 §6.7): dynamic component columns,
 * totals row; export as XLSX (amounts as numbers) or CSV.
 */
final class PayrollReportController extends Controller
{
    private const FIXED = ['period', 'employee_no', 'full_name', 'department', 'designation', 'payment_days', 'gross_pay', 'total_deductions', 'net_pay', 'payment_status'];

    private const MONEY = ['gross_pay', 'total_deductions', 'net_pay'];

    public function register(Request $request, PayrollExports $exports): Response|StreamedResponse|BinaryFileResponse
    {
        $this->requirePermission('payroll.slip.view-all');
        $filters = $request->validate([
            'period_from' => ['nullable', 'date'], 'period_to' => ['nullable', 'date'], 'payroll_run_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'], 'payment_status' => ['nullable', 'in:unpaid,withheld,paid'],
            'include_employer' => ['nullable', 'boolean'], 'export' => ['nullable', 'in:csv,xlsx'],
        ]);
        $data = $exports->register($filters);
        $departments = Department::query()->pluck('name', 'id');
        $designations = Designation::query()->pluck('name', 'id');
        $data['rows'] = array_map(fn (array $row) => $row + [
            'department' => $departments[$row['department_id']] ?? '', 'designation' => $designations[$row['designation_id']] ?? '',
        ], $data['rows']);

        if (isset($filters['export'])) {
            return $this->export($data, (string) $filters['export']);
        }

        return Inertia::render('Payroll/Reports/Register', $data + [
            'filters' => $filters,
            'options' => [
                'departments' => $departments->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all(),
                'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->all(),
                'runs' => PayrollRun::query()->orderByDesc('period')->limit(36)->get()->map(fn (PayrollRun $r) => ['id' => $r->id, 'number' => $r->number, 'period' => $r->period->format('Y-m')])->all(),
            ],
        ]);
    }

    /**
     * @param  array{columns: list<array{code: string, name: string, kind: string}>, rows: list<array<string, mixed>>, totals: array<string, string>}  $data
     */
    private function export(array $data, string $format): BinaryFileResponse|StreamedResponse
    {
        $xlsx = $format === 'xlsx';
        $headers = [...array_map(fn (string $k) => (string) __('payroll.register.columns.'.$k), self::FIXED), ...array_map(fn (array $c) => $c['name'], $data['columns'])];
        $cell = function (string $key, mixed $value) use ($xlsx): float|string {
            if ($key === 'payment_status') {
                return (string) __('payroll.enums.payment_status.'.$value);
            }
            $money = in_array($key, self::MONEY, true) || ! in_array($key, self::FIXED, true);

            return $xlsx && $money && is_numeric($value) ? (float) $value : (string) $value;
        };
        $rows = [];
        foreach ($data['rows'] as $row) {
            $rows[] = [...array_map(fn (string $k) => $cell($k, $row[$k] ?? ''), self::FIXED), ...array_map(fn (array $c) => $cell($c['code'], $row[$c['code']] ?? '0.00'), $data['columns'])];
        }
        $total = [];
        foreach (self::FIXED as $k) {
            $total[] = $k === 'full_name' ? (string) __('payroll.pdf.total') : (in_array($k, self::MONEY, true) ? $cell($k, $data['totals'][$k]) : '');
        }
        foreach ($data['columns'] as $c) {
            $total[] = $cell($c['code'], $data['totals'][$c['code']]);
        }
        $rows[] = $total;

        if ($xlsx) {
            return SpreadsheetExport::download('daftar-gaji', $headers, $rows, 'xlsx');
        }

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'daftar-gaji.csv', ['Content-Type' => 'text/csv']);
    }
}
