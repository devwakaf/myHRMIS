<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\CopyHolidayListToNextYear;
use App\Domain\Core\Actions\GenerateWeeklyOffs;
use App\Domain\Core\Actions\ImportPublicHolidays;
use App\Domain\Core\Actions\SaveHoliday;
use App\Domain\Core\Actions\SaveHolidayList;
use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\PublicHoliday;
use App\Domain\Core\Services\HolidayListAnalyzer;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Senarai Cuti Umum (B1.13, B3.7, B4).
 */
final class HolidayListController extends Controller
{
    public function index(Request $request, HolidayListAnalyzer $analyzer): Response
    {
        $this->requirePermission('core.holiday.manage', 'core.holiday.view');

        $lists = HolidayList::query()->with('holidays')->orderByDesc('from_date')->get()->map(fn (HolidayList $l) => [
            'id' => $l->id,
            'name' => $l->name,
            'name_en' => $l->name_en,
            'display_name' => $l->displayName(),
            'from_date' => $l->from_date->toDateString(),
            'to_date' => $l->to_date->toDateString(),
            'state' => $l->state?->value,
            'state_label' => $l->state?->label(),
            'weekly_off_days' => $l->weekly_off_days ?? [],
            'half_day_weekly_off_days' => $l->half_day_weekly_off_days ?? [],
            'notes' => $l->notes,
            'is_active' => $l->is_active,
            'totals' => $analyzer->totals($l),
        ])->values();

        return Inertia::render('Core/HolidayLists/Index', [
            'lists' => $lists,
            'states' => MalaysianState::options(),
            'canManage' => $request->user()->can('core.holiday.manage'),
        ]);
    }

    public function store(Request $request, SaveHolidayList $save): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        $list = $save->handle(null, $this->validated($request, null));

        return redirect('/holiday-lists/'.$list->id)->with('success', __('common.saved'));
    }

    public function show(Request $request, HolidayList $holidayList, HolidayListAnalyzer $analyzer): Response
    {
        $this->requirePermission('core.holiday.manage', 'core.holiday.view');
        $holidayList->load('holidays');

        $years = PublicHoliday::query()->distinct()->orderBy('year')->pluck('year');

        return Inertia::render('Core/HolidayLists/Show', [
            'list' => [
                'id' => $holidayList->id,
                'name' => $holidayList->name,
                'name_en' => $holidayList->name_en,
                'display_name' => $holidayList->displayName(),
                'from_date' => $holidayList->from_date->toDateString(),
                'to_date' => $holidayList->to_date->toDateString(),
                'state' => $holidayList->state?->value,
                'state_label' => $holidayList->state?->label(),
                'weekly_off_days' => $holidayList->weekly_off_days ?? [],
                'half_day_weekly_off_days' => $holidayList->half_day_weekly_off_days ?? [],
                'notes' => $holidayList->notes,
                'is_active' => $holidayList->is_active,
            ],
            'holidays' => $holidayList->holidays->map(fn (Holiday $h) => [
                'id' => $h->id,
                'date' => $h->date->toDateString(),
                'name' => $h->name,
                'name_en' => $h->name_en,
                'display_name' => $h->displayName(),
                'kind' => $h->kind->value,
                'kind_label' => $h->kind->label(),
                'is_half_day' => $h->is_half_day,
                'is_mandatory' => $h->is_mandatory,
            ])->values(),
            'totals' => $analyzer->totals($holidayList),
            'suggestions' => $analyzer->replacementSuggestions($holidayList),
            'kinds' => HolidayKind::options(),
            'states' => MalaysianState::options(),
            'catalogueYears' => $years,
            'canManage' => $request->user()->can('core.holiday.manage'),
        ]);
    }

    public function update(Request $request, HolidayList $holidayList, SaveHolidayList $save): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        $save->handle($holidayList, $this->validated($request, $holidayList));

        return $this->done('common.saved');
    }

    public function storeHoliday(Request $request, HolidayList $holidayList, SaveHoliday $save): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        $save->handle($holidayList, null, $this->holidayData($request));

        return $this->done('common.saved');
    }

    public function updateHoliday(Request $request, HolidayList $holidayList, Holiday $holiday, SaveHoliday $save): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        abort_unless($holiday->holiday_list_id === $holidayList->id, 404);
        $save->handle($holidayList, $holiday, $this->holidayData($request));

        return $this->done('common.saved');
    }

    public function destroyHoliday(Request $request, HolidayList $holidayList, Holiday $holiday, SaveHoliday $save): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        abort_unless($holiday->holiday_list_id === $holidayList->id, 404);
        $save->delete($holiday);

        return $this->done('common.deleted');
    }

    public function generateWeeklyOffs(HolidayList $holidayList, GenerateWeeklyOffs $generate): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        $count = $generate->handle($holidayList);

        return back()->with('success', __('core.holiday.weekly_offs_generated', ['count' => $count]));
    }

    public function import(Request $request, HolidayList $holidayList, ImportPublicHolidays $import): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_merge(['federal'], MalaysianState::values()))],
        ]);
        $count = $import->handle($holidayList, (int) $data['year'], $data['scopes']);

        return back()->with('success', __('core.holiday.imported', ['count' => $count]));
    }

    public function copy(Request $request, HolidayList $holidayList, CopyHolidayListToNextYear $copy): RedirectResponse
    {
        $this->requirePermission('core.holiday.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $new = $copy->handle($holidayList, $data['name']);

        return redirect('/holiday-lists/'.$new->id)->with('success', __('core.holiday.copied'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?HolidayList $list): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('holiday_lists', 'name')
                ->where('organisation_id', OrganisationContext::id())->ignore($list?->id)],
            'name_en' => ['nullable', 'string', 'max:255'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'state' => ['nullable', Rule::enum(MalaysianState::class)],
            'weekly_off_days' => ['array'],
            'weekly_off_days.*' => ['integer', 'between:1,7'],
            'half_day_weekly_off_days' => ['array'],
            'half_day_weekly_off_days.*' => ['integer', 'between:1,7'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayData(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(HolidayKind::class)],
            'is_half_day' => ['boolean'],
            'is_mandatory' => ['boolean'],
        ]);
    }
}
