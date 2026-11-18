<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Events\HolidayListChanged;
use App\Domain\Core\Models\HolidayList;

/**
 * "Jana Hari Rehat Mingguan" (B3.7): add a weekly_off row for every free date
 * whose ISO weekday is in weekly_off_days; half-day weekdays get is_half_day.
 * Idempotent: dates that already have a row are skipped.
 */
final class GenerateWeeklyOffs
{
    /**
     * @return int number of rows added
     */
    public function handle(HolidayList $list): int
    {
        $full = array_map('intval', $list->weekly_off_days ?? []);
        $half = array_map('intval', $list->half_day_weekly_off_days ?? []);
        if ($full === [] && $half === []) {
            return 0;
        }

        $taken = $list->holidays()->pluck('date')->map(fn ($d) => $d->toDateString())->flip();
        $rows = [];
        $now = now();

        foreach ($list->range()->dates() as $date) {
            $weekday = $date->dayOfWeekIso;
            $isFull = in_array($weekday, $full, true);
            $isHalf = ! $isFull && in_array($weekday, $half, true);
            if ((! $isFull && ! $isHalf) || $taken->has($date->toDateString())) {
                continue;
            }

            $rows[] = [
                'holiday_list_id' => $list->id,
                'date' => $date->toDateString(),
                'name' => (string) __('core.holiday.weekly_off_name', [], 'ms'),
                'name_en' => (string) __('core.holiday.weekly_off_name', [], 'en'),
                'kind' => HolidayKind::WeeklyOff->value,
                'is_half_day' => $isHalf,
                'is_mandatory' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $list->holidays()->getModel()->newQuery()->insert($chunk);
        }

        if ($rows !== []) {
            HolidayListChanged::dispatch($list->id, array_column($rows, 'date'), []);
        }

        return count($rows);
    }
}
