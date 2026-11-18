<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CW###')),
            'name' => 'Cawangan '.fake()->unique()->numerify('####'),
            'state' => 'PHG',
            'city' => 'Kuantan',
            'postcode' => '25000',
            'is_active' => true,
        ];
    }
}
