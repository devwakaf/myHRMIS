<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Grade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grade>
 */
class GradeFactory extends Factory
{
    protected $model = Grade::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('G###')),
            'name' => 'Gred '.fake()->unique()->numberBetween(1, 9999),
            'rank' => fake()->numberBetween(1, 50),
            'is_active' => true,
        ];
    }
}
