<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Support\Facades\DB;

/**
 * "Salin ke Tahun Baharu" (B3.7): copy name, weekly-off pattern and organisation
 * holidays one year forward. Public holidays move every year and must be re-imported.
 */
final class CopyHolidayListToNextYear
{
    public function __construct(private readonly GenerateWeeklyOffs $weeklyOffs) {}

    public function handle(HolidayList $source, string $newName): HolidayList
    {
        if (HolidayList::query()->where('name', $newName)->exists()) {
            throw DomainValidation::withMessages(['name' => __('validation.unique', ['attribute' => __('validation.attributes.name')])]);
        }

        return DB::transaction(function () use ($source, $newName): HolidayList {
            $copy = HolidayList::query()->create([
                'name' => $newName,
                'name_en' => null,
                'from_date' => $source->from_date->addYearNoOverflow()->toDateString(),
                'to_date' => $source->to_date->addYearNoOverflow()->toDateString(),
                'state' => $source->state,
                'weekly_off_days' => $source->weekly_off_days,
                'half_day_weekly_off_days' => $source->half_day_weekly_off_days,
                'notes' => $source->notes,
                'is_active' => true,
            ]);

            foreach ($source->holidays()->where('kind', HolidayKind::Organisation->value)->get() as $holiday) {
                $date = $holiday->date->addYearNoOverflow();
                if ($copy->covers($date) && ! $copy->holidays()->whereDate('date', $date->toDateString())->exists()) {
                    $copy->holidays()->create([
                        'date' => $date->toDateString(),
                        'name' => $holiday->name,
                        'name_en' => $holiday->name_en,
                        'kind' => HolidayKind::Organisation,
                        'is_half_day' => $holiday->is_half_day,
                        'is_mandatory' => false,
                    ]);
                }
            }

            $this->weeklyOffs->handle($copy);

            return $copy;
        });
    }
}
