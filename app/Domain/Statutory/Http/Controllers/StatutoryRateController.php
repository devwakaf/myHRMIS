<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Http\Controllers;

use App\Domain\Payroll\Http\PayrollPresenter;
use App\Domain\Statutory\Actions\RateSetActions;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Models\StatutoryFileFormat;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\RateCsvImporter;
use App\Domain\Statutory\Services\RateTableCatalogue;
use App\Domain\Statutory\Services\StatutoryFixtures;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kadar Statutori (spec-04 §1.1): rate sets per scheme with their tables;
 * Salin Set Baharu, Import CSV, Jalankan Ujian Fixture, Terbitkan, Sahkan.
 * Read: system.reference-data.view (PO/PA); write: Super Admin.
 */
final class StatutoryRateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->requirePermission('system.reference-data.manage', 'system.reference-data.view');

        return Inertia::render('Statutory/Rates/Index', [
            'sets' => StatutoryRateSet::query()->orderBy('scheme')->orderByDesc('effective_from')->get()->map(fn ($s) => PayrollPresenter::rateSet($s))->all(),
            'file_formats' => StatutoryFileFormat::query()->orderBy('scheme')->get(['id', 'scheme', 'code', 'name', 'version', 'effective_from', 'status', 'source_url'])->all(),
            'options' => ['schemes' => RateScheme::options()],
            'can' => ['manage' => $request->user()->can('system.reference-data.manage')],
        ]);
    }

    public function show(Request $request, StatutoryRateSet $set): Response
    {
        $this->requirePermission('system.reference-data.manage', 'system.reference-data.view');
        $tables = [];
        foreach (RateTableCatalogue::tablesFor($set->scheme) as $table) {
            $tables[$table] = DB::table($table)->where('rate_set_id', $set->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return Inertia::render('Statutory/Rates/Show', [
            'set' => PayrollPresenter::rateSet($set),
            'tables' => $tables,
            'csv_columns' => array_intersect_key(RateTableCatalogue::COLUMNS, array_flip(RateTableCatalogue::tablesFor($set->scheme))),
            'can' => ['manage' => $request->user()->can('system.reference-data.manage'),
                'verify' => $request->user()->can('system.reference-data.manage') && $set->created_by !== $request->user()->id],
        ]);
    }

    public function copy(Request $request, StatutoryRateSet $set, RateSetActions $actions): RedirectResponse
    {
        $this->requirePermission('system.reference-data.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'unique:statutory_rate_sets,code'], 'effective_from' => ['required', 'date'], 'source_url' => ['nullable', 'string', 'max:500']]);
        $copy = $actions->copy($set, (string) $data['code'], CarbonImmutable::parse((string) $data['effective_from']), $data['source_url'] ?? null);

        return redirect('/statutory/rates/'.$copy->id)->with('success', __('statutory.messages.copied'));
    }

    public function import(Request $request, StatutoryRateSet $set, RateCsvImporter $importer): RedirectResponse
    {
        $this->requirePermission('system.reference-data.manage');
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'table' => ['nullable', 'string']]);
        $result = $importer->import($set->scheme, (string) file_get_contents($request->file('file')->getRealPath()), $set->code, null, null, $data['table'] ?? null);

        return back()->with('success', __('statutory.messages.imported', ['rows' => $result['rows']]));
    }

    public function fixtures(Request $request, StatutoryRateSet $set, StatutoryFixtures $fixtures): JsonResponse
    {
        $this->requirePermission('system.reference-data.manage', 'system.reference-data.view');

        return new JsonResponse(['failures' => $fixtures->run($set)]);
    }

    public function publish(StatutoryRateSet $set, RateSetActions $actions): RedirectResponse
    {
        $this->requirePermission('system.reference-data.manage');
        $failures = $actions->publish($set);
        if ($failures !== []) {
            return back()->with('error', __('statutory.messages.fixtures_failed', ['failures' => implode('; ', $failures)]));
        }

        return back()->with('success', __('statutory.messages.published'));
    }

    public function verify(Request $request, StatutoryRateSet $set, RateSetActions $actions): RedirectResponse
    {
        $this->requirePermission('system.reference-data.manage');
        $actions->verify($set, $request->user());

        return back()->with('success', __('statutory.messages.verified'));
    }

    public function destroy(StatutoryRateSet $set, RateSetActions $actions): RedirectResponse
    {
        $this->requirePermission('system.reference-data.manage');
        $actions->delete($set);

        return redirect('/statutory/rates')->with('success', __('common.deleted'));
    }
}
