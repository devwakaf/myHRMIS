<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\HolidayList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HolidayList>
 */
class HolidayListFactory extends Factory
{
    protected $model = HolidayList::class;

    public function definition(): array
    {
        return [
            'name' => 'Senarai '.fake()->unique()->numerify('####'),
            'from_date' => '2026-01-01',
            'to_date' => '2026-12-31',
            'state' => 'PHG',
            'weekly_off_days' => [6, 7],
            'half_day_weekly_off_days' => [],
            'is_active' => true,
        ];
    }
}
