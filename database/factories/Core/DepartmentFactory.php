<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a child of the root department of the active organisation.
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'parent_id' => fn () => Department::query()->whereNull('parent_id')->value('id')
                ?? Department::query()->create(['code' => 'ROOT', 'name' => 'Ibu Pejabat'])->id,
            'code' => strtoupper(fake()->unique()->bothify('JB###')),
            'name' => 'Jabatan '.fake()->unique()->numerify('####'),
            'is_active' => true,
        ];
    }

    public function root(): static
    {
        return $this->state(fn () => ['parent_id' => null]);
    }
}
