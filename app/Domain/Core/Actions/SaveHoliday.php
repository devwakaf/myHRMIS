<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Events\HolidayListChanged;
use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Shared\Support\DomainValidation;
use Carbon\CarbonImmutable;

/**
 * Add, edit or remove one holiday (B3.7): date inside the list range, one row
 * per date. Every change fires HolidayListChanged with the added/removed dates.
 */
final class SaveHoliday
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(HolidayList $list, ?Holiday $holiday, array $data): Holiday
    {
        $date = CarbonImmutable::parse((string) $data['date']);
        $kind = HolidayKind::tryFrom((string) ($data['kind'] ?? ''));
        $errors = [];

        if (! $list->covers($date)) {
            $errors['date'] = __('core.holiday.outside_range');
        }
        if ($kind === null) {
            $errors['kind'] = __('validation.in', ['attribute' => __('validation.attributes.kind')]);
        }
        $duplicate = $list->holidays()->whereDate('date', $date->toDateString())
            ->when($holiday, fn ($q) => $q->whereKeyNot($holiday->id))->exists();
        if ($duplicate) {
            $errors['date'] = __('core.holiday.duplicate_date');
        }
        DomainValidation::throwIf($errors);

        $values = [
            'date' => $date->toDateString(),
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'kind' => $kind,
            'is_half_day' => (bool) ($data['is_half_day'] ?? false),
            'is_mandatory' => (bool) ($data['is_mandatory'] ?? false),
        ];

        if ($holiday === null) {
            $holiday = $list->holidays()->create($values);
            HolidayListChanged::dispatch($list->id, [$date->toDateString()], []);

            return $holiday;
        }

        $oldDate = $holiday->date->toDateString();
        $holiday->update($values);
        if ($oldDate !== $date->toDateString()) {
            HolidayListChanged::dispatch($list->id, [$date->toDateString()], [$oldDate]);
        } else {
            HolidayListChanged::dispatch($list->id, [], []);
        }

        return $holiday;
    }

    public function delete(Holiday $holiday): void
    {
        $date = $holiday->date->toDateString();
        $listId = $holiday->holiday_list_id;
        $holiday->delete();

        HolidayListChanged::dispatch($listId, [], [$date]);
    }
}
