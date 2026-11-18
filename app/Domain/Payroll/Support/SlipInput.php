<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use BcMath\Number;
use Carbon\CarbonImmutable;

/**
 * Fully loaded input of the pure SlipCalculator (spec-04 §4): days, components
 * in evaluation scope, applicable Additional Salaries, statutory inputs and the
 * employee attributes the formula language exposes.
 */
final class SlipInput
{
    /**
     * @param  list<ComponentSpec>  $components
     * @param  list<AdditionalLine>  $additional
     * @param  array<string, Number|bool|string>  $attributes  GRADE, DESIGNATION, … , AGE, YEARS_OF_SERVICE
     * @param  list<ComponentSpec>  $extraComponents  components that may be added without a structure row (additional-only, POTONGAN_CTG)
     */
    public function __construct(
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly PaymentDays $days,
        public readonly string $base,
        public readonly array $components,
        public readonly array $additional,
        public readonly StatutoryInputs $statutory,
        public readonly array $attributes = [],
        public readonly array $extraComponents = [],
        public readonly string $lwpPresentation = 'reduce_earning',
        public readonly string $netPayRounding = 'none',
        public readonly bool $statutoryErrorsAsWarnings = false,
    ) {}
}
