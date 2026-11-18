<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Draft assignment at organisation level.
 *
 * @extends Factory<HolidayListAssignment>
 */
class HolidayListAssignmentFactory extends Factory
{
    protected $model = HolidayListAssignment::class;

    public function definition(): array
    {
        return [
            'holiday_list_id' => HolidayList::factory(),
            'assignable_type' => 'organisation',
            'assignable_id' => fn () => OrganisationContext::id(),
            'effective_from' => '2026-01-01',
        ];
    }
}
