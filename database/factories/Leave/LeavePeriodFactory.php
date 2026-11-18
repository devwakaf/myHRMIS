<?php

declare(strict_types=1);

namespace Database\Factories\Leave;

use App\Domain\Leave\Models\LeavePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Calendar-year Tempoh Cuti (default 2026).
 *
 * @extends Factory<LeavePeriod>
 */
class LeavePeriodFactory extends Factory
{
    protected $model = LeavePeriod::class;

    public function definition(): array
    {
        return ['name' => '2026', 'from_date' => '2026-01-01', 'to_date' => '2026-12-31'];
    }

    public function year(int $year): static
    {
        return $this->state(fn () => ['name' => (string) $year, 'from_date' => "{$year}-01-01", 'to_date' => "{$year}-12-31"]);
    }
}
