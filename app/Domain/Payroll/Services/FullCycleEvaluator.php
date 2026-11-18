<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\DailyRatePolicy;
use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Formula\ArrayScope;
use App\Domain\Payroll\Formula\DependencyGraph;
use App\Domain\Payroll\Formula\Evaluator;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Shared\Money\Num;
use BcMath\Number;
use Carbon\CarbonImmutable;

/**
 * Full-cycle (contractual, un-prorated) value of every component of an
 * assignment for a month — the basis of OtWageBaseProvider, CompensationQuery
 * and arrear increments. Statutory components evaluate to 0 here.
 */
final class FullCycleEvaluator
{
    public function __construct(
        private readonly SlipContextBuilder $builder,
        private readonly PaymentDaysCalculator $days,
    ) {}

    /**
     * @return array<string, array{amount: string, ot_wage_base: bool, arrear_eligible: bool, kind: ComponentKind, component_id: int, prorate: bool}>
     */
    public function values(Employee $employee, SalaryAssignment $assignment, CarbonImmutable $month): array
    {
        $structure = SalaryStructure::query()->findOrFail($assignment->salary_structure_id);
        $specs = $this->builder->componentSpecs($structure, $assignment);
        $start = $month->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $organisation = Organisation::query()->findOrFail($employee->organisation_id);
        $policy = DailyRatePolicy::for($organisation);
        $working = $this->days->workingDays($employee, $start, $end);

        $scope = new ArrayScope($this->builder->attributes($employee, $end) + [
            'BASE' => new Number(Num::of((string) $assignment->base_salary)),
            'CALENDAR_DAYS' => new Number((string) $start->daysInMonth),
            'WORKING_DAYS' => new Number($working ?? '0'),
            'DIVISOR' => new Number($policy->divisor),
            'PAYMENT_FRACTION' => new Number('1'),
            'PAYMENT_DAYS' => new Number($policy->divisor),
            'LWP_DAYS' => new Number('0'),
            'ABSENT_DAYS' => new Number('0'),
            'PERIOD_MONTH' => new Number((string) $start->month),
            'PERIOD_YEAR' => new Number((string) $start->year),
            'IS_NEW_JOINER' => false,
            'IS_LEAVER' => false,
        ]);

        $graph = new DependencyGraph;
        $byCode = [];
        foreach ($specs as $spec) {
            $byCode[$spec->code] = $spec;
            $graph->addNode($spec->code, $spec->sortKey());
        }
        $usesGross = false;
        foreach ($specs as $spec) {
            foreach ($spec->references() as $ref) {
                if (isset($byCode[$ref])) {
                    $graph->addEdge($spec->code, $ref);
                } elseif ($ref === 'GROSS') {
                    $usesGross = true;
                    $graph->addEdge($spec->code, 'GROSS');
                }
            }
        }
        if ($usesGross) {
            $graph->addNode('GROSS', '1-999999-GROSS');
            foreach ($specs as $spec) {
                if ($spec->kind === ComponentKind::Earning && $spec->flag('include_in_gross')) {
                    $graph->addEdge('GROSS', $spec->code);
                }
            }
        }

        $values = [];
        foreach ($graph->sort() as $code) {
            if ($code === 'GROSS') {
                $gross = '0';
                foreach ($values as $v) {
                    if ($v['kind'] === ComponentKind::Earning) {
                        $gross = Num::add($gross, $v['amount']);
                    }
                }
                $scope->set('GROSS', new Number($gross));

                continue;
            }
            $spec = $byCode[$code];
            $evaluator = new Evaluator($code);
            $active = $spec->condition === null || $evaluator->condition($spec->condition, $scope);
            $amount = match (true) {
                ! $active, $spec->isStatutory(), $spec->method === CalculationMethod::Input => '0',
                $spec->method === CalculationMethod::Formula && $spec->formula !== null => (string) $evaluator->number($spec->formula, $scope),
                default => Num::of($spec->amount),
            };
            $amount = $spec->rounding->apply($amount);
            $scope->set($code, new Number($amount));
            $scope->setFull($code, new Number($amount));
            $values[$code] = [
                'amount' => $amount, 'ot_wage_base' => $spec->flag('ot_wage_base'), 'arrear_eligible' => $spec->arrearEligible,
                'kind' => $spec->kind, 'component_id' => $spec->id, 'prorate' => $spec->prorate,
            ];
        }

        return $values;
    }
}
