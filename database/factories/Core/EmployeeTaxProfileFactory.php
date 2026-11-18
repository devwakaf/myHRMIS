<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeTaxProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTaxProfile>
 */
class EmployeeTaxProfileFactory extends Factory
{
    protected $model = EmployeeTaxProfile::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'tax_residency' => 'resident',
            'mtd_scheme' => 'normal',
            'pcb_category' => 1,
        ];
    }
}
