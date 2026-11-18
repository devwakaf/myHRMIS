<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\HolidayList;
use App\Domain\Shared\Support\DomainValidation;
use Carbon\CarbonImmutable;

/**
 * Create/update a Senarai Cuti Umum (B3.7): from ≤ to, span ≤ 366 days, and
 * existing holidays must stay inside the range.
 */
final class SaveHolidayList
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?HolidayList $list, array $data): HolidayList
    {
        $from = CarbonImmutable::parse((string) $data['from_date']);
        $to = CarbonImmutable::parse((string) $data['to_date']);
        $errors = [];

        if ($to->lessThan($from)) {
            $errors['to_date'] = __('core.holiday.range_order');
        } elseif ($from->diffInDays($to) + 1 > 366) {
            $errors['to_date'] = __('core.holiday.range_too_long');
        }

        foreach (['weekly_off_days', 'half_day_weekly_off_days'] as $field) {
            $days = array_values(array_unique(array_map('intval', (array) ($data[$field] ?? []))));
            foreach ($days as $d) {
                if ($d < 1 || $d > 7) {
                    $errors[$field] = __('core.holiday.invalid_weekday');
                }
            }
            sort($days);
            $data[$field] = $days;
        }

        if ($list !== null && ! isset($errors['to_date'])) {
            $outside = $list->holidays()
                ->where(fn ($q) => $q->whereDate('date', '<', $from->toDateString())->orWhereDate('date', '>', $to->toDateString()))
                ->exists();
            if ($outside) {
                $errors['from_date'] = __('core.holiday.holidays_outside_range');
            }
        }

        DomainValidation::throwIf($errors);

        if ($list === null) {
            return HolidayList::query()->create($data);
        }

        $list->update($data);

        return $list;
    }
}
