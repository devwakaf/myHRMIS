<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Reports\AttendanceReports;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Support\SpreadsheetExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Kehadiran (spec-03 §7): Bulanan, Shift, Lewat Masuk / Keluar Awal, OT,
 * Roster and Log Check-in with XLSX/CSV export. Needs attendance.reports.view;
 * the check-in log also needs attendance.view-all. Coordinates are never exported.
 */
final class AttendanceReportController extends Controller
{
    public const REPORTS = ['monthly', 'shift', 'late', 'overtime', 'roster', 'checkins'];

    private const SCREEN_LIMIT = 1000;

    private const EXPORT_LIMIT = 50000;

    public function __construct(private readonly AttendanceReports $reports) {}

    public function show(Request $request, string $report): Response
    {
        $this->authoriseReport($report);
        $data = $this->data($request, $report, self::SCREEN_LIMIT);

        return Inertia::render('Attendance/Reports/Show', $data + [
            'report' => $report,
            'reports' => $this->visibleReports($request),
            'filters' => $this->filters($request),
            'options' => [
                'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name', 'employee_no'])
                    ->map(fn (Employee $e) => ['value' => $e->id, 'label' => $e->full_name.' ('.$e->employee_no.')'])->all(),
                'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->all(),
                'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name])->all(),
                'geofence_results' => GeofenceResult::options(),
                'claim_statuses' => array_map(fn (ApprovalStatus $s) => ['value' => $s->value, 'label' => $s->label()], ApprovalStatus::cases()),
                'attendance_statuses' => AttendanceStatus::options(),
            ],
        ]);
    }

    public function export(Request $request, string $report): BinaryFileResponse
    {
        $this->authoriseReport($report);
        $data = $this->data($request, $report, self::EXPORT_LIMIT);
        $format = (string) $request->query('format', 'xlsx');

        if ($data['kind'] === 'matrix') {
            return $this->exportMatrix($report, $data, $format);
        }

        $columns = $data['columns'];
        $headers = array_map(fn (array $c) => (string) $c['label'], $columns);
        $rows = array_map(fn (array $row) => array_map(fn (array $c) => $row[$c['key']] ?? null, $columns), $data['rows']);

        return SpreadsheetExport::download('laporan-kehadiran-'.$report, $headers, $rows, $format);
    }

    private function authoriseReport(string $report): void
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);
        $this->requirePermission('attendance.reports.view');
        if ($report === 'checkins') {
            $this->requirePermission('attendance.view-all');
        }
    }

    /**
     * @return list<string>
     */
    private function visibleReports(Request $request): array
    {
        $all = $request->user()->is_super_admin || $request->user()->can('attendance.view-all');

        return $all ? self::REPORTS : array_values(array_diff(self::REPORTS, ['checkins']));
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return array_map('strval', $request->only(['from', 'to', 'month', 'employee_id', 'branch_id', 'department_id', 'min_count', 'no_grace', 'geofence_result', 'status']));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Request $request, string $report, int $limit): array
    {
        $f = $this->filters($request);
        $showPay = $request->user()->is_super_admin || $request->user()->can('core.employee.view-compensation');

        return match ($report) {
            'monthly' => $this->reports->monthly($f),
            'shift' => $this->reports->shift($f, $limit),
            'late' => $this->reports->late($f),
            'overtime' => $this->reports->overtime($f, $showPay),
            'roster' => $this->reports->roster($f),
            default => $this->reports->checkins($f, $limit),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function exportMatrix(string $report, array $data, string $format): BinaryFileResponse
    {
        $headers = array_merge(
            array_map(fn (array $c) => (string) $c['label'], $data['columns']),
            array_map(fn (array $d) => (string) $d['day'], $data['days']),
            array_map(fn (array $c) => (string) $c['label'], $data['total_columns']),
        );
        $rows = [];
        foreach ($data['rows'] as $row) {
            $cells = array_map(fn (array $cell) => trim($cell['c'].($cell['f'] !== '' ? ' '.implode('+', str_split($cell['f'])) : '')), $row['cells']);
            $rows[] = array_merge(
                array_map(fn (array $c) => $row[$c['key']] ?? null, $data['columns']),
                $cells,
                array_map(fn (array $c) => $row[$c['key']] ?? null, $data['total_columns']),
            );
        }

        return SpreadsheetExport::download('laporan-kehadiran-'.$report, $headers, $rows, $format);
    }
}
