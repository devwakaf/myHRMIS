<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bank>
 */
class BankFactory extends Factory
{
    protected $model = Bank::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('BNK###')),
            'name' => 'Bank '.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }
}
