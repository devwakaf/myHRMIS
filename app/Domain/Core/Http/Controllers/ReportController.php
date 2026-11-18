<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Http\Resources\Lookup;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Reports\EmployeeAnalyticsReport;
use App\Domain\Core\Reports\EmployeeInfoReport;
use App\Domain\Core\Reports\PeopleReports;
use App\Domain\Core\Reports\UpcomingDatesReport;
use App\Domain\Core\Services\EmployeeQuery;
use App\Domain\Shared\Support\SpreadsheetExport;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Teras (B5). All reports respect the organisation scope and the
 * sensitive-field ability; exports to XLSX/CSV.
 */
final class ReportController extends Controller
{
    public const REPORTS = ['employees', 'analytics', 'birthdays', 'leavers', 'upcoming', 'org-chart'];

    public function show(Request $request, string $report): Response
    {
        $this->requirePermission('core.reports.view');
        $sensitive = $request->user()->can('core.employee.view-sensitive');
        $data = $this->data($request, $report, $sensitive);

        return Inertia::render('Core/Reports/Show', array_merge($data, [
            'report' => $report,
            'reports' => self::REPORTS,
            'filters' => $request->query(),
            'options' => array_merge(Lookup::employeeForm(), [
                'statuses' => EmployeeStatus::options(),
                'citizenships' => CitizenshipStatus::options(),
                'dimensions' => array_map(fn ($d) => ['value' => $d, 'label' => __('core.reports.dimensions.'.$d)], EmployeeAnalyticsReport::DIMENSIONS),
                'columns' => array_map(fn ($c) => ['value' => $c, 'label' => __('core.employee.columns.'.$c)],
                    app(EmployeeInfoReport::class)->allowedColumns($sensitive)),
            ]),
            'sensitive' => $sensitive,
        ]));
    }

    public function export(Request $request, string $report): BinaryFileResponse
    {
        $this->requirePermission('core.reports.view');
        abort_if($report === 'org-chart', 404);
        $sensitive = $request->user()->can('core.employee.view-sensitive');
        $data = $this->data($request, $report, $sensitive);

        if ($report === 'analytics') {
            $rows = array_map(fn ($r) => [$r['label'], $r['count'], $r['percent']], $data['result']['rows']);

            return SpreadsheetExport::download('analitik-kakitangan', [
                (string) __('core.reports.columns.group'), (string) __('core.reports.columns.count'), (string) __('core.reports.columns.percent'),
            ], $rows, (string) $request->query('format', 'xlsx'));
        }

        $columns = $data['columns'];
        $headers = array_map(fn ($c) => (string) $c['label'], $columns);
        $rows = array_map(fn ($row) => array_map(fn ($c) => $row[$c['key']] ?? null, $columns), $data['rows']);

        return SpreadsheetExport::download('laporan-'.$report, $headers, $rows, (string) $request->query('format', 'xlsx'));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Request $request, string $report, bool $sensitive): array
    {
        $col = fn (string $key, string $group = 'core.employee.columns') => ['key' => $key, 'label' => (string) __($group.'.'.$key)];

        switch ($report) {
            case 'employees':
                $info = app(EmployeeInfoReport::class);
                $requested = (array) $request->query('columns', ['employee_no', 'full_name', 'department', 'designation', 'status', 'date_of_joining']);
                $columns = array_values(array_intersect($requested, $info->allowedColumns($sensitive)));
                $filters = $request->only(array_merge(EmployeeQuery::FILTERS, ['as_at']));

                return [
                    'columns' => array_map(fn ($c) => $col($c), $columns),
                    'rows' => $info->rows($filters, $columns, $sensitive),
                ];

            case 'analytics':
                return ['result' => app(EmployeeAnalyticsReport::class)->build($request->query())];

            case 'birthdays':
                $month = max(1, min(12, $request->integer('month', (int) today()->month)));
                $columns = [$col('full_name'), $col('department'), $col('birthday')];
                if ($sensitive) {
                    $columns[] = $col('date_of_birth');
                    $columns[] = $col('age');
                }

                return ['columns' => $columns, 'rows' => app(PeopleReports::class)->birthdays($month, $sensitive)];

            case 'leavers':
                $from = CarbonImmutable::parse((string) $request->query('from', today()->startOfYear()->toDateString()));
                $to = CarbonImmutable::parse((string) $request->query('to', today()->toDateString()));

                return [
                    'columns' => [$col('employee_no'), $col('full_name'), $col('department'), $col('relieving_date'), $col('reason'), $col('tenure_years'), $col('eligible_for_rehire')],
                    'rows' => app(PeopleReports::class)->leavers($from, $to),
                ];

            case 'upcoming':
                return [
                    'columns' => [$col('employee_no'), $col('full_name'), $col('kind_label'), $col('date'), $col('days_left'), $col('detail')],
                    'rows' => app(UpcomingDatesReport::class)->rows($request->query()),
                ];

            default: // org-chart
                return ['tree' => $this->orgChart()];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orgChart(): array
    {
        $employees = Employee::query()->where('status', '!=', EmployeeStatus::Left->value)->with('designation')->get();
        $byManager = $employees->groupBy(fn (Employee $e) => (int) $e->reports_to_id);
        $ids = $employees->pluck('id')->all();

        $build = function (int $managerId, int $depth) use (&$build, $byManager): array {
            if ($depth > 15) {
                return [];
            }

            return $byManager->get($managerId, collect())->sortBy('full_name')->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->full_name,
                'designation' => $e->designation?->displayName(),
                'children' => $build($e->id, $depth + 1),
            ])->values()->all();
        };

        // Roots: no manager, or a manager outside the active list.
        $roots = $employees->filter(fn (Employee $e) => $e->reports_to_id === null || ! in_array($e->reports_to_id, $ids, true));

        return $roots->sortBy('full_name')->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->full_name,
            'designation' => $e->designation?->displayName(),
            'children' => $build($e->id, 1),
        ])->values()->all();
    }
}
