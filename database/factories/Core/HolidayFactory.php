<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Holiday;
use App\Domain\Core\Models\HolidayList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'holiday_list_id' => HolidayList::factory(),
            'date' => '2026-08-31',
            'name' => 'Hari Kebangsaan',
            'kind' => 'public',
            'is_half_day' => false,
            'is_mandatory' => true,
        ];
    }
}
