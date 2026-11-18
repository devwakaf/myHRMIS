<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\EmploymentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmploymentType>
 */
class EmploymentTypeFactory extends Factory
{
    protected $model = EmploymentType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('JP###')),
            'name' => 'Tetap '.fake()->unique()->numberBetween(1, 9999),
            'category' => 'permanent',
            'requires_end_date' => false,
            'is_active' => true,
        ];
    }

    public function contract(): static
    {
        return $this->state(fn () => [
            'category' => 'contract',
            'requires_end_date' => true,
            'name' => 'Kontrak '.fake()->unique()->numberBetween(1, 9999),
        ]);
    }
}
