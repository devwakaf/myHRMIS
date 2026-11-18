<?php

declare(strict_types=1);

namespace App\Domain\Leave\Http\Controllers;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\UnpaidLeaveQuery;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Reports\LeaveReports;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Support\SpreadsheetExport;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Cuti (spec-02 §6.2): Baki Cuti Kakitangan, Ledger Cuti, Ringkasan Baki
 * Cuti, CTG mengikut Tempoh, Penggunaan Had Gabungan and Baki Negatif, with
 * XLSX/CSV export. Attachments and sick-leave reasons never appear in any row.
 *
 * The CTG report is open to everyone holding leave.reports.view (Payroll);
 * every other report additionally needs leave.allocation.manage (HR).
 */
final class LeaveReportController extends Controller
{
    public const REPORTS = ['balances', 'ledger', 'summary', 'unpaid', 'caps', 'negative'];

    private const LEDGER_SCREEN_LIMIT = 1000;

    private const LEDGER_EXPORT_LIMIT = 50000;

    public function show(Request $request, string $report): Response
    {
        $this->authoriseReport($request, $report);
        $data = $this->data($request, $report, self::LEDGER_SCREEN_LIMIT);

        return Inertia::render('Leave/Reports/Show', $data + [
            'report' => $report,
            'reports' => $this->visibleReports($request),
            'filters' => $this->filters($request),
            'options' => [
                'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])
                    ->map(fn (Employee $e) => ['value' => $e->id, 'label' => $e->full_name.' ('.$e->employee_no.')'])->all(),
                'types' => LeaveType::query()->orderBy('sort_order')->get()->map(fn (LeaveType $t) => ['value' => $t->id, 'label' => $t->displayName()])->all(),
                'kinds' => MovementKind::options(),
            ],
        ]);
    }

    public function export(Request $request, string $report): BinaryFileResponse
    {
        $this->authoriseReport($request, $report);
        $data = $this->data($request, $report, self::LEDGER_EXPORT_LIMIT);
        $columns = $data['columns'];
        $headers = array_map(fn (array $c) => (string) $c['label'], $columns);
        $rows = array_map(fn (array $row) => array_map(fn (array $c) => $row[$c['key']] ?? null, $columns), $data['rows']);

        return SpreadsheetExport::download('laporan-cuti-'.$report, $headers, $rows, (string) $request->query('format', 'xlsx'));
    }

    private function authoriseReport(Request $request, string $report): void
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);
        $this->requirePermission('leave.reports.view');
        if ($report !== 'unpaid') {
            $this->requirePermission('leave.allocation.manage');
        }
    }

    /**
     * @return list<string>
     */
    private function visibleReports(Request $request): array
    {
        $hr = $request->user()->is_super_admin || $request->user()->can('leave.allocation.manage');

        return $hr ? self::REPORTS : ['unpaid'];
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return array_map('strval', $request->only(['from', 'to', 'date', 'month', 'employee_id', 'leave_type_id', 'kind']));
    }

    /**
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, truncated?: bool}
     */
    private function data(Request $request, string $report, int $limit): array
    {
        $col = fn (string $key) => ['key' => $key, 'label' => (string) __('leave.reports.columns.'.$key)];
        $date = fn (string $key, CarbonImmutable $default) => CarbonImmutable::parse((string) $request->query($key, $default->toDateString()));
        $reports = app(LeaveReports::class);

        switch ($report) {
            case 'balances':
                $from = $date('from', today()->startOfYear()->toImmutable());
                $to = $date('to', today()->toImmutable());
                $rows = $reports->balanceSummary(
                    $from, $to,
                    $request->filled('employee_id') ? [$request->integer('employee_id')] : null,
                    $request->filled('leave_type_id') ? [$request->integer('leave_type_id')] : null,
                );
                usort($rows, fn (array $a, array $b) => [$a['employee'], $a['leave_type']] <=> [$b['employee'], $b['leave_type']]);
                $rows = array_map(fn (array $r) => $this->normaliseDays($r, ['opening', 'credited', 'taken', 'encashed', 'reduced', 'carried_out', 'expired', 'closing', 'reserved', 'available']), $rows);

                return ['columns' => array_map($col, ['employee_no', 'employee', 'leave_type', 'opening', 'credited', 'taken', 'encashed', 'reduced', 'carried_out', 'expired', 'closing', 'reserved', 'available']), 'rows' => $rows];

            case 'ledger':
                $query = LeaveMovement::query()
                    ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
                    ->when($request->filled('leave_type_id'), fn ($q) => $q->where('leave_type_id', $request->integer('leave_type_id')))
                    ->when($request->filled('kind'), fn ($q) => $q->where('kind', (string) $request->query('kind')))
                    ->when($request->filled('from'), fn ($q) => $q->whereDate('posted_on', '>=', (string) $request->query('from')))
                    ->when($request->filled('to'), fn ($q) => $q->whereDate('posted_on', '<=', (string) $request->query('to')))
                    ->orderByDesc('posted_on')->orderByDesc('id');
                $movements = $query->limit($limit + 1)->get();
                $truncated = $movements->count() > $limit;
                $movements = $movements->take($limit);
                $employees = Employee::query()->whereIn('id', $movements->pluck('employee_id')->unique())->get()->keyBy('id');
                $types = LeaveType::query()->get()->keyBy('id');
                $rows = $movements->map(fn (LeaveMovement $m) => [
                    'posted_on' => $m->posted_on->toDateString(),
                    'employee_no' => $employees->get($m->employee_id)?->employee_no,
                    'employee' => $employees->get($m->employee_id)?->full_name,
                    'leave_type' => $types->get($m->leave_type_id)?->displayName(),
                    'kind' => $m->kind->label(),
                    'days' => Dec::norm((string) $m->days),
                    'date_from' => $m->date_from->toDateString(),
                    'date_to' => $m->date_to->toDateString(),
                    'reverses' => $m->reverses_movement_id,
                    'note' => $m->note,
                ])->values()->all();

                return ['columns' => array_map($col, ['posted_on', 'employee_no', 'employee', 'leave_type', 'kind', 'days', 'date_from', 'date_to', 'reverses', 'note']), 'rows' => $rows, 'truncated' => $truncated];

            case 'summary':
                $on = $date('date', today()->toImmutable());
                $types = LeaveType::query()->where('is_active', true)->orderBy('sort_order')->get()->filter(fn (LeaveType $t) => $t->hasBalance())->values();
                $balance = app(LeaveBalance::class);
                $rows = Employee::query()->notLeft()->orderBy('full_name')->get()->map(function (Employee $e) use ($types, $balance, $on) {
                    $row = ['employee_no' => $e->employee_no, 'employee' => $e->full_name];
                    foreach ($types as $t) {
                        $row['type_'.$t->id] = Dec::norm($balance->on($e, $t, $on)['available']);
                    }

                    return $row;
                })->all();
                $columns = [$col('employee_no'), $col('employee')];
                foreach ($types as $t) {
                    $columns[] = ['key' => 'type_'.$t->id, 'label' => $t->code];
                }

                return ['columns' => $columns, 'rows' => $rows];

            case 'unpaid':
                $month = CarbonImmutable::parse(((string) $request->query('month', today()->format('Y-m'))).'-01')->startOfMonth();
                $period = new DateRange($month, $month->endOfMonth());
                $query = app(UnpaidLeaveQuery::class);
                $rows = [];
                foreach (Employee::query()->orderBy('full_name')->get() as $e) {
                    $approved = $query->unpaidDays($e, $period)->total;
                    $pending = $query->pendingUnpaidDays($e, $period);
                    if (Dec::isPositive($approved) || Dec::isPositive($pending)) {
                        $rows[] = ['employee_no' => $e->employee_no, 'employee' => $e->full_name, 'unpaid_days' => Dec::norm($approved), 'pending_unpaid_days' => Dec::norm($pending)];
                    }
                }

                return ['columns' => array_map($col, ['employee_no', 'employee', 'unpaid_days', 'pending_unpaid_days']), 'rows' => $rows];

            case 'caps':
                $on = $date('date', today()->toImmutable());
                $employees = Employee::query()->get()->keyBy('id');
                $rows = array_map(fn (array $r) => [
                    'cap' => $r['cap'],
                    'employee_no' => $employees->get($r['employee_id'])?->employee_no,
                    'employee' => $employees->get($r['employee_id'])?->full_name,
                    'cap_days' => Dec::norm($r['cap_days']),
                    'used' => Dec::norm($r['used']),
                    'room' => Dec::norm($r['room']),
                ], $reports->capUsage($on));

                return ['columns' => array_map($col, ['cap', 'employee_no', 'employee', 'cap_days', 'used', 'room']), 'rows' => $rows];

            default: // negative
                $on = $date('date', today()->toImmutable());
                $employees = Employee::query()->get()->keyBy('id');
                $rows = array_map(fn (array $r) => [
                    'employee_no' => $employees->get($r['employee_id'])?->employee_no,
                    'employee' => $r['employee'],
                    'leave_type' => $r['leave_type'],
                    'balance' => Dec::norm($r['balance']),
                ], $reports->negativeBalances($on));

                return ['columns' => array_map($col, ['employee_no', 'employee', 'leave_type', 'balance']), 'rows' => $rows];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function normaliseDays(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            $row[$key] = Dec::norm((string) $row[$key]);
        }

        return $row;
    }
}
