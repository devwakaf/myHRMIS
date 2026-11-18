<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\DailyRatePolicy;
use App\Domain\Payroll\Support\ComponentSpec;
use App\Domain\Payroll\Support\PaymentDays;
use App\Domain\Payroll\Support\SlipInput;
use App\Domain\Payroll\Support\SlipResult;
use App\Domain\Payroll\Support\StatutoryInputs;
use App\Domain\Payroll\Support\TaxYearTotals;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Services\StatutoryRateRepository;
use App\Domain\Statutory\Support\StatutoryPerson;
use BcMath\Number;
use Carbon\CarbonImmutable;

/**
 * Save-time dry run (spec-04 §3.6): evaluate a structure against a synthetic
 * context — BASE 3,000.00, a full month of 22 working days, citizen aged 35,
 * PCB category 1 — or a real employee. Statutory nodes use the rate sets in
 * force today; a missing set becomes a warning, any formula error blocks.
 */
final class StructureDryRun
{
    public function __construct(
        private readonly SlipCalculator $calculator,
        private readonly StatutoryRateRepository $rates,
        private readonly SlipContextBuilder $builder,
    ) {}

    /**
     * @param  list<ComponentSpec>  $specs
     */
    public function run(array $specs, ?Employee $employee = null, string $base = '3000.00', ?CarbonImmutable $on = null): SlipResult
    {
        $on ??= CarbonImmutable::today();
        $start = $on->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $organisation = Organisation::query()->findOrFail(OrganisationContext::id());
        $policy = DailyRatePolicy::for($organisation);

        $epf = $this->rates->setFor(RateScheme::Epf, $end);
        $socso = $this->rates->setFor(RateScheme::Socso, $end);
        $eis = $this->rates->setFor(RateScheme::Eis, $end);
        $mtd = $this->rates->setFor(RateScheme::Mtd, $end);
        $hrdc = $this->rates->setFor(RateScheme::Hrdc, $end);
        $reliefs = $this->rates->setFor(RateScheme::TaxReliefs, $end);

        $person = $employee === null
            ? new StatutoryPerson(CitizenshipStatus::Citizen, $start->subYears(35)->subMonth(), $start, $end)
            : new StatutoryPerson($employee->citizenship_status, $employee->date_of_birth, $start, $end, $employee->kwsp_exempt, $employee->eis_exempt, $employee->work_pass_expiry);

        $statutory = new StatutoryInputs(
            person: $person,
            epf: $epf === null ? null : $this->rates->epf($epf),
            socso: $socso === null ? null : $this->rates->socso($socso),
            eis: $eis === null ? null : $this->rates->eis($eis),
            mtd: $mtd === null ? null : $this->rates->mtd($mtd),
            hrdc: $hrdc === null ? null : $this->rates->hrdc($hrdc),
            reliefs: $reliefs === null ? null : $this->rates->reliefs($reliefs),
            ytd: new TaxYearTotals($start->year),
            hrdcRegistered: $organisation->hrd_corp_registered,
            hrdcRate: $organisation->getAttribute('hrd_corp_levy_rate') === null ? null : (string) $organisation->getAttribute('hrd_corp_levy_rate'),
            headcount: 10,
        );

        $attributes = $employee === null
            ? [
                'GRADE' => '', 'DESIGNATION' => '', 'DEPARTMENT' => '', 'BRANCH' => '', 'EMPLOYMENT_CATEGORY' => 'permanent',
                'CITIZENSHIP' => CitizenshipStatus::Citizen->value, 'AGE' => new Number('35'), 'YEARS_OF_SERVICE' => new Number('5'),
            ]
            : $this->builder->attributes($employee, $end);

        $input = new SlipInput(
            periodStart: $start,
            periodEnd: $end,
            days: PaymentDays::fullMonth((string) $start->daysInMonth, '22', $policy->basis->value, $policy->divisor),
            base: $base,
            components: $specs,
            additional: [],
            statutory: $statutory,
            attributes: $attributes,
            extraComponents: [],
            statutoryErrorsAsWarnings: true,
        );

        return $this->calculator->calculate($input);
    }
}
