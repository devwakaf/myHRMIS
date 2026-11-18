<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Adapters;

use App\Domain\Attendance\Contracts\OtWageBaseProvider;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\CompensationQuery;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Services\FullCycleEvaluator;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;

/**
 * Payroll's contractual-wage ports (spec-04 §4.2):
 * - OtWageBaseProvider (spec-03 §5.3): Σ full-cycle components flagged ot_wage_base
 *   of the assignment effective on the 1st of the month (LWP ignored).
 * - CompensationQuery (spec-02 §4): full-cycle GAJI_POKOK, else BASE.
 * Both return null without a submitted assignment.
 */
final class PayrollCompensationAdapter implements CompensationQuery, OtWageBaseProvider
{
    public const BASIC_CODE = 'GAJI_POKOK';

    public function __construct(private readonly FullCycleEvaluator $evaluator) {}

    public function monthlyWageFor(Employee $employee, CarbonImmutable $month): ?Money
    {
        return OrganisationContext::run((int) $employee->organisation_id, function () use ($employee, $month): ?Money {
            $assignment = SalaryAssignment::effectiveFor($employee->id, $month->startOfMonth());
            if ($assignment === null) {
                return null;
            }
            $sum = '0';
            foreach ($this->evaluator->values($employee, $assignment, $month) as $value) {
                if ($value['ot_wage_base']) {
                    $sum = Num::add($sum, $value['amount']);
                }
            }

            return Money::of(Num::money($sum));
        });
    }

    public function monthlyBasic(Employee $employee, CarbonImmutable $date): ?Money
    {
        return OrganisationContext::run((int) $employee->organisation_id, function () use ($employee, $date): ?Money {
            $assignment = SalaryAssignment::effectiveFor($employee->id, $date);
            if ($assignment === null) {
                return null;
            }
            $values = $this->evaluator->values($employee, $assignment, $date);

            return Money::of(Num::money($values[self::BASIC_CODE]['amount'] ?? (string) $assignment->base_salary));
        });
    }
}
