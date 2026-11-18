<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plain Organisation row. Use CreateOrganisation (or Tests\Support helpers) when
 * the roles, settings and default workflows are needed too.
 *
 * @extends Factory<Organisation>
 */
class OrganisationFactory extends Factory
{
    protected $model = Organisation::class;

    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->bothify('??##'));

        return [
            'name' => 'Organisasi '.fake()->unique()->company(),
            'short_code' => $code,
            'registration_type' => 'company',
            'ssm_no' => fake()->numerify('2020########'),
            'kwsp_employer_no' => fake()->numerify('#########'),
            'perkeso_employer_code' => fake()->numerify('E##########'),
            'lhdn_employer_no' => fake()->numerify('##########'),
            'address_line1' => 'No. '.fake()->numberBetween(1, 200).', Jalan Contoh',
            'postcode' => '25000',
            'city' => 'Kuantan',
            'state' => 'PHG',
            'daily_rate_basis' => 'fixed_divisor',
            'daily_rate_divisor' => '26.00',
            'is_active' => true,
        ];
    }
}
