<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    protected $model = Designation::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('JW###')),
            'name' => 'Jawatan '.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
