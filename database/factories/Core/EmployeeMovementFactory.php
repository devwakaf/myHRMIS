<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Draft transfer effective today.
 *
 * @extends Factory<EmployeeMovement>
 */
class EmployeeMovementFactory extends Factory
{
    protected $model = EmployeeMovement::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => 'transfer',
            'effective_date' => now()->toDateString(),
            'reason' => 'Keperluan operasi',
        ];
    }
}
