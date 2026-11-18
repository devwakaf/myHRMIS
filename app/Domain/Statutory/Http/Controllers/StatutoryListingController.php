<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Http\Controllers;

use App\Domain\Payroll\Pdf\PayrollPdf;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\SpreadsheetExport;
use App\Domain\Statutory\Files\StatutoryListings;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Senarai Caruman KWSP / PERKESO / SIP, Senarai PCB &amp; Zakat and Senarai Levi HRD Corp
 * (spec-04 §6.6): on screen, as PDF and as XLSX/CSV. Needs statutory.files.export.
 * Names and statutory numbers appear; full IC numbers are never written to logs.
 */
final class StatutoryListingController extends Controller
{
    public function show(Request $request, string $kind, StatutoryListings $listings): Response
    {
        $period = $this->period($request, $kind);
        $data = $listings->build($kind, $period);

        return Inertia::render('Statutory/Listings/Show', $data + [
            'kind' => $kind,
            'kinds' => StatutoryListings::KINDS,
            'period' => $period->format('Y-m'),
            'unverified' => $this->unverified($kind, $period),
        ]);
    }

    /** XLSX / CSV / PDF of the listing. */
    public function export(Request $request, string $kind, StatutoryListings $listings, PayrollPdf $pdf): BinaryFileResponse|HttpResponse
    {
        $period = $this->period($request, $kind);
        $data = $listings->build($kind, $period);
        $format = (string) $request->query('format', 'xlsx');
        $title = (string) __('statutory.listings.names.'.$kind);

        if ($format === 'pdf') {
            return response($pdf->listing($title, $period->format('Y-m'), $data, $this->unverified($kind, $period)), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="senarai-'.$kind.'-'.$period->format('Y-m').'.pdf"',
            ]);
        }

        $headers = array_map(fn (array $c) => $c['label'], $data['columns']);
        $numeric = $format !== 'csv';
        $rows = array_map(fn (array $row) => array_map(fn (array $c) => $numeric && $c['money'] ? (float) ($row[$c['key']] ?? 0) : ($row[$c['key']] ?? ''), $data['columns']), $data['rows']);
        $rows[] = array_map(fn (array $c, int $i) => $i === 0 ? (string) __('payroll.pdf.total') : (isset($data['totals'][$c['key']]) ? ($numeric ? (float) $data['totals'][$c['key']] : $data['totals'][$c['key']]) : ''),
            $data['columns'], array_keys($data['columns']));

        return SpreadsheetExport::download('senarai-'.$kind.'-'.$period->format('Y-m'), $headers, $rows, $format);
    }

    private function period(Request $request, string $kind): CarbonImmutable
    {
        $this->requirePermission('statutory.files.export');
        abort_unless(in_array($kind, StatutoryListings::KINDS, true), 404);
        $value = (string) $request->query('period', CarbonImmutable::today()->subMonth()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1, 404);

        return CarbonImmutable::parse($value.'-01');
    }

    /** True when any rate set used by the month's slips for this scheme is not verified. */
    private function unverified(string $kind, CarbonImmutable $period): bool
    {
        $scheme = ['kwsp' => 'epf', 'perkeso' => 'socso', 'sip' => 'eis', 'pcb' => 'mtd', 'hrdc' => 'hrdc'][$kind];
        $column = ['epf' => 'epf_rate_set_id', 'socso' => 'socso_rate_set_id', 'eis' => 'eis_rate_set_id', 'mtd' => 'mtd_rate_set_id', 'hrdc' => 'hrdc_rate_set_id'][$scheme];
        $ids = DB::table('salary_slip_statutory')
            ->join('salary_slips', 'salary_slips.id', '=', 'salary_slip_statutory.salary_slip_id')
            ->where('salary_slips.organisation_id', OrganisationContext::id())
            ->where('salary_slips.status', 'submitted')->whereDate('salary_slips.period', $period->toDateString())
            ->whereNotNull('salary_slip_statutory.'.$column)->distinct()->pluck('salary_slip_statutory.'.$column);

        return $ids->isNotEmpty() && StatutoryRateSet::query()->whereIn('id', $ids)->where('status', '!=', 'verified')->exists();
    }
}
