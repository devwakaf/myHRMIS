<?php

declare(strict_types=1);

namespace Database\Factories\Leave;

use App\Domain\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Paid per-period leave type in the active organisation.
 * States: earned(), unpaid(), perEvent(), compensatory().
 *
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        return [
            'code' => 'T'.fake()->unique()->numerify('####'),
            'name' => 'Cuti '.fake()->unique()->word(),
            'colour' => '#2563eb',
            'entitlement_basis' => 'per_period',
            'pay_mode' => 'paid',
            'paid_fraction' => '1',
            'counts_holidays' => false,
            'allow_half_day' => true,
            'proration_method' => 'none',
            'is_active' => true,
        ];
    }

    public function earned(string $frequency = 'monthly', string $timing = 'sub_period_end', ?string $step = '0.5'): static
    {
        return $this->state(fn () => ['entitlement_basis' => 'earned', 'accrual_frequency' => $frequency, 'accrual_timing' => $timing, 'rounding_step' => $step]);
    }

    public function unpaid(): static
    {
        return $this->state(fn () => ['entitlement_basis' => 'unlimited', 'pay_mode' => 'unpaid', 'paid_fraction' => '0']);
    }

    public function perEvent(string $maxDays = '7'): static
    {
        return $this->state(fn () => ['entitlement_basis' => 'per_event', 'per_event_max_days' => $maxDays]);
    }

    public function compensatory(int $expiryDays = 90): static
    {
        return $this->state(fn () => ['entitlement_basis' => 'compensatory', 'compensatory_expiry_days' => $expiryDays]);
    }
}
