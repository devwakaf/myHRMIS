<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\HolidayList;
use Carbon\CarbonImmutable;

/**
 * Totals and rest-day clash suggestions for a holiday list (B3.7).
 */
final class HolidayListAnalyzer
{
    /**
     * Public/state/replacement days and weekly offs; half days count 0.5.
     *
     * @return array{public: string, state: string, replacement: string, organisation: string, ad_hoc: string, weekly_off: string, total: string}
     */
    public function totals(HolidayList $list): array
    {
        $sums = ['public' => '0', 'state' => '0', 'replacement' => '0', 'organisation' => '0', 'ad_hoc' => '0', 'weekly_off' => '0'];
        foreach ($list->holidays as $holiday) {
            $weight = $holiday->is_half_day ? '0.5' : '1';
            $sums[$holiday->kind->value] = bcadd($sums[$holiday->kind->value], $weight, 1);
        }

        $total = '0';
        foreach ($sums as $key => $value) {
            $sums[$key] = bcadd($value, '0', 1);
            $total = bcadd($total, $value, 1);
        }

        return array_merge($sums, ['total' => $total]);
    }

    /**
     * When a public/state holiday falls on a weekly off, suggest a replacement
     * holiday on the next date that is neither a weekly off nor a holiday.
     *
     * @return list<array{holiday_id: int, holiday_date: string, holiday_name: string, suggested_date: string}>
     */
    public function replacementSuggestions(HolidayList $list): array
    {
        $holidays = $list->holidays;
        $byDate = $holidays->keyBy(fn (Holiday $h) => $h->date->toDateString());
        $weeklyOff = array_map('intval', $list->weekly_off_days ?? []);
        $suggested = [];
        $out = [];

        $isWeeklyOff = function (CarbonImmutable $date) use ($weeklyOff, $byDate): bool {
            $row = $byDate->get($date->toDateString());

            return in_array($date->dayOfWeekIso, $weeklyOff, true)
                || ($row !== null && $row->kind === HolidayKind::WeeklyOff && ! $row->is_half_day);
        };

        foreach ($holidays as $holiday) {
            if (! in_array($holiday->kind, [HolidayKind::PublicHoliday, HolidayKind::State], true)) {
                continue;
            }
            if (! in_array($holiday->date->dayOfWeekIso, $weeklyOff, true)) {
                continue;
            }

            $alreadyReplaced = $holidays->contains(fn (Holiday $h) => $h->kind === HolidayKind::Replacement
                && $h->date->greaterThan($holiday->date) && $h->date->diffInDays($holiday->date, true) <= 7);
            if ($alreadyReplaced) {
                continue;
            }

            $candidate = $holiday->date->addDay();
            while ($list->covers($candidate)
                && ($isWeeklyOff($candidate) || $byDate->has($candidate->toDateString()) || isset($suggested[$candidate->toDateString()]))) {
                $candidate = $candidate->addDay();
            }

            if (! $list->covers($candidate)) {
                continue;
            }

            $suggested[$candidate->toDateString()] = true;
            $out[] = [
                'holiday_id' => $holiday->id,
                'holiday_date' => $holiday->date->toDateString(),
                'holiday_name' => $holiday->displayName(),
                'suggested_date' => $candidate->toDateString(),
            ];
        }

        return $out;
    }
}
