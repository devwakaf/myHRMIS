<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Events\HolidayListChanged;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\PublicHoliday;

/**
 * "Import Cuti Umum" (B3.7): copy catalogue rows for a year and scopes into the
 * list as `public` (federal) or `state`, skipping dates already present.
 */
final class ImportPublicHolidays
{
    /**
     * @param  list<string>  $scopes  'federal' and/or state codes
     * @return int rows added
     */
    public function handle(HolidayList $list, int $year, array $scopes): int
    {
        $taken = $list->holidays()->pluck('date')->map(fn ($d) => $d->toDateString())->flip()->all();
        $added = [];

        $catalogue = PublicHoliday::query()
            ->where('year', $year)
            ->whereIn('scope', $scopes)
            ->orderBy('date')
            ->get();

        foreach ($catalogue as $row) {
            $date = $row->date->toDateString();
            if (isset($taken[$date]) || ! $list->covers($row->date)) {
                continue;
            }

            $list->holidays()->create([
                'date' => $date,
                'name' => $row->name_ms,
                'name_en' => $row->name_en,
                'kind' => $row->scope === 'federal' ? HolidayKind::PublicHoliday : HolidayKind::State,
                'is_half_day' => false,
                'is_mandatory' => $row->is_mandatory,
                'source_public_holiday_id' => $row->id,
            ]);
            $taken[$date] = true;
            $added[] = $date;
        }

        if ($added !== []) {
            HolidayListChanged::dispatch($list->id, $added, []);
        }

        return count($added);
    }
}
