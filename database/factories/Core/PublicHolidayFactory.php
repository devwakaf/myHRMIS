<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\PublicHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    protected $model = PublicHoliday::class;

    public function definition(): array
    {
        return [
            'year' => 2026,
            'date' => '2026-08-31',
            'name_ms' => 'Cuti '.fake()->unique()->numerify('####'),
            'name_en' => null,
            'scope' => 'federal',
            'is_mandatory' => false,
        ];
    }
}
