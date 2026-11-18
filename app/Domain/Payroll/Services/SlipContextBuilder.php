<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Core\Enums\CitizenshipStatus;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\SalaryPaymentMode;
use App\Domain\Core\Enums\TaxResidency;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\EmployeeSnapshot;
use App\Domain\Core\Services\StatutoryProfile;
use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\DeclarationType;
use App\Domain\Payroll\Enums\StatutoryKey;
use App\Domain\Payroll\Exceptions\PayrollCalculationException;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Support\AdditionalLine;
use App\Domain\Payroll\Support\ComponentSpec;
use App\Domain\Payroll\Support\PaymentDays;
use App\Domain\Payroll\Support\SlipInput;
use App\Domain\Payroll\Support\StatutoryInputs;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\StatutoryRateRepository;
use App\Domain\Statutory\Support\StatutoryPerson;
use BcMath\Number;
use Carbon\CarbonImmutable;

/**
 * Loads everything one slip needs from the database and returns a pure
 * SlipInput (spec-04 §4.1–§4.2). Also exposes the structure → ComponentSpec
 * mapping used by the dry run, arrears and the Payroll-implemented ports.
 */
final class SlipContextBuilder
{
    /** Statutory key → scheme whose rate set it needs. */
    private const KEY_SCHEME = [
        'epf_employee' => RateScheme::Epf, 'epf_employer' => RateScheme::Epf,
        'socso_employee' => RateScheme::Socso, 'socso_employer' => RateScheme::Socso, 'skbbk_employee' => RateScheme::Socso,
        'eis_employee' => RateScheme::Eis, 'eis_employer' => RateScheme::Eis,
        'pcb' => RateScheme::Mtd, 'hrdc_levy' => RateScheme::Hrdc,
    ];

    /** @var array<string, int> */
    private array $headcounts = [];

    public function __construct(
        private readonly PaymentDaysCalculator $daysCalculator,
        private readonly StatutoryRateRepository $rates,
        private readonly TaxYearCalculator $taxYears,
    ) {}

    /**
     * @return array{input: SlipInput, assignment: SalaryAssignment, structure: SalaryStructure, days: array<string, mixed>, warnings: list<string>, rate_sets: list<StatutoryRateSet>}
     */
    public function build(Employee $employee, CarbonImmutable $period, ?string $lwpOverride = null, ?string $pcbOverride = null): array
    {
        $organisation = Organisation::query()->findOrFail($employee->organisation_id);
        $settings = PayrollSettings::current($organisation->id);
        $periodStart = $period->startOfMonth();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $daysInfo = $this->daysCalculator->compute($employee, $organisation, $periodStart, $settings, $lwpOverride);
        /** @var PaymentDays $days */
        $days = $daysInfo['days'];
        $actualStart = $daysInfo['actual_start'];

        $assignment = SalaryAssignment::effectiveFor($employee->id, $actualStart)
            ?? throw PayrollCalculationException::of('no_assignment', ['date' => $actualStart->format('d/m/Y')]);
        $structure = SalaryStructure::query()->find($assignment->salary_structure_id);
        if ($structure === null || ! $structure->is_active) {
            throw PayrollCalculationException::of('structure_inactive');
        }

        $components = $this->componentSpecs($structure, $assignment);
        $extras = $this->extraSpecs();
        $warnings = $this->identityChecks($employee, $periodStart);

        $profile = StatutoryProfile::for($employee, $periodEnd);
        $tax = $profile->taxProfile;
        if (($tax['zakat_via_payroll'] ?? false) && Num::isPositive((string) ($tax['zakat_monthly_amount'] ?? '0'))) {
            $components = $this->withZakat($components, $extras, (string) $tax['zakat_monthly_amount']);
        }

        $additional = $this->additionalLines($employee, $periodStart, $components);

        // Rate sets of every needed scheme must be effective on period end (§4.1).
        $neededSchemes = [];
        foreach ($components as $spec) {
            if ($spec->isStatutory() && $spec->statutoryKey !== null && isset(self::KEY_SCHEME[$spec->statutoryKey->value])) {
                $neededSchemes[self::KEY_SCHEME[$spec->statutoryKey->value]->value] = self::KEY_SCHEME[$spec->statutoryKey->value];
            }
        }
        if (isset($neededSchemes[RateScheme::Mtd->value])) {
            $neededSchemes[RateScheme::Epf->value] = RateScheme::Epf;
        }
        $sets = [];
        foreach ($neededSchemes as $scheme) {
            $sets[$scheme->value] = $this->rates->requireSet($scheme, $periodEnd);
        }
        $reliefSet = $this->rates->setFor(RateScheme::TaxReliefs, $periodEnd);
        foreach ($sets as $set) {
            if (! $set->isVerified()) {
                $warnings[] = (string) __('payroll.warnings.unverified_rate_set', ['code' => $set->code]);
            }
        }

        $ytd = $this->taxYears->totals($employee->id, $periodStart->year, $periodStart);
        [$tp1, $zakatExternal] = $this->declarationsFor($employee->id, $periodStart);

        $person = new StatutoryPerson(
            $employee->citizenship_status, $employee->date_of_birth, $periodStart, $periodEnd,
            $employee->kwsp_exempt, $employee->eis_exempt, $employee->work_pass_expiry, $profile->firstInsuredAge, $profile->lindung24,
        );

        $statutory = new StatutoryInputs(
            person: $person,
            epf: isset($sets['epf']) ? $this->rates->epf($sets['epf']) : null,
            socso: isset($sets['socso']) ? $this->rates->socso($sets['socso']) : null,
            eis: isset($sets['eis']) ? $this->rates->eis($sets['eis']) : null,
            mtd: isset($sets['mtd']) ? $this->rates->mtd($sets['mtd']) : null,
            hrdc: isset($sets['hrdc']) ? $this->rates->hrdc($sets['hrdc']) : null,
            reliefs: $reliefSet === null ? null : $this->rates->reliefs($reliefSet),
            ytd: $ytd,
            pcbCategory: (int) ($tax['pcb_category'] ?? 1),
            resident: ($tax['tax_residency'] ?? TaxResidency::Resident->value) === TaxResidency::Resident->value,
            mtdScheme: (string) ($tax['mtd_scheme'] ?? 'normal'),
            disabledSelf: $employee->is_disabled,
            spouseDisabled: (bool) ($tax['spouse_disabled'] ?? false),
            claimsChildRelief: (bool) ($tax['claims_child_relief'] ?? false),
            childrenBasic: (int) ($tax['children_basic'] ?? 0),
            childrenHigherEd: (int) ($tax['children_higher_ed'] ?? 0),
            childrenDisabled: (int) ($tax['children_disabled'] ?? 0),
            childrenDisabledHigherEd: (int) ($tax['children_disabled_higher_ed'] ?? 0),
            tp1ThisMonth: $tp1,
            zakatExternal: $zakatExternal,
            pcbOverride: $pcbOverride,
            epfEmployeeRateOverride: $assignment->epf_employee_rate_override ?? $profile->kwspEmployeeRateOverride,
            epfEmployerRateOverride: $assignment->epf_employer_rate_override,
            hrdcRegistered: $organisation->hrd_corp_registered,
            hrdcRate: $organisation->getAttribute('hrd_corp_levy_rate') === null ? null : (string) $organisation->getAttribute('hrd_corp_levy_rate'),
            headcount: $this->malaysianHeadcount($organisation->id, $periodStart),
            y2Basis: $settings->y2_basis,
        );

        if (Num::isPositive($daysInfo['pending_unpaid'])) {
            $warnings[] = (string) __('payroll.warnings.pending_unpaid_leave', ['days' => Num::trim($daysInfo['pending_unpaid'])]);
        }
        if ($days->unmarkedDays !== '0.000' && $settings->days_source === 'leave_and_attendance') {
            $warnings[] = (string) __('payroll.warnings.unmarked_days', ['days' => Num::trim($days->unmarkedDays)]);
        }
        if ($employee->status === EmployeeStatus::Suspended) {
            $warnings[] = (string) __('payroll.warnings.suspended');
        }
        if ($lwpOverride !== null) {
            $warnings[] = (string) __('payroll.warnings.lwp_override');
        }

        $input = new SlipInput(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            days: $days,
            base: (string) $assignment->base_salary,
            components: $components,
            additional: $additional,
            statutory: $statutory,
            attributes: $this->attributes($employee, $periodEnd),
            extraComponents: $extras,
            lwpPresentation: $settings->lwp_presentation,
            netPayRounding: $settings->net_pay_rounding,
        );

        return [
            'input' => $input,
            'assignment' => $assignment,
            'structure' => $structure,
            'days' => $daysInfo,
            'warnings' => $warnings,
            'rate_sets' => array_values($sets),
        ];
    }

    /**
     * Structure rows → specs (assignment amount overrides replace the row amount).
     *
     * @return list<ComponentSpec>
     */
    public function componentSpecs(SalaryStructure $structure, ?SalaryAssignment $assignment = null): array
    {
        $overrides = $assignment === null ? [] : $assignment->amounts()->pluck('amount', 'salary_component_id')->map(fn ($v) => (string) $v)->all();
        $specs = [];
        $position = 0;
        foreach ($structure->rows()->with('component')->get() as $row) {
            $component = $row->component;
            if (! $component->is_active) {
                continue;
            }
            $specs[] = ComponentSpec::fromComponent($component, $row, $overrides[$component->id] ?? null, ++$position);
        }

        return $specs;
    }

    /**
     * Every active component of the Organisasi as a possible extra line.
     *
     * @return list<ComponentSpec>
     */
    public function extraSpecs(): array
    {
        return SalaryComponent::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (SalaryComponent $c) => ComponentSpec::fromComponent($c))->values()->all();
    }

    /**
     * @param  list<ComponentSpec>  $components
     * @param  list<ComponentSpec>  $extras
     * @return list<ComponentSpec>
     */
    private function withZakat(array $components, array $extras, string $monthly): array
    {
        foreach ($components as $i => $spec) {
            if ($spec->statutoryKey === StatutoryKey::Zakat) {
                $components[$i] = $spec->with(amount: Num::money($monthly), method: CalculationMethod::Fixed);

                return $components;
            }
        }
        foreach ($extras as $spec) {
            if ($spec->statutoryKey === StatutoryKey::Zakat) {
                $components[] = $spec->with(amount: Num::money($monthly), method: CalculationMethod::Fixed);
            }
        }

        return $components;
    }

    /**
     * Submitted Additional Salaries that apply to the month.
     *
     * @param  list<ComponentSpec>  $components
     * @return list<AdditionalLine>
     */
    private function additionalLines(Employee $employee, CarbonImmutable $period, array $components): array
    {
        $lines = [];
        $rows = AdditionalSalary::query()->forPeriod($employee->id, $period)->orderBy('id')->get();
        $inStructure = array_map(fn (ComponentSpec $s) => $s->id, $components);
        foreach ($rows as $row) {
            if ($row->mode === AdditionalMode::Replace && ! in_array($row->salary_component_id, $inStructure, true)) {
                throw PayrollCalculationException::of('replace_not_in_structure', ['number' => $row->number]);
            }
            $lines[] = new AdditionalLine(
                $row->id, $row->salary_component_id, (string) $row->amount, $row->mode, $row->prorate, $row->source_type,
                $row->quantity === null ? null : (string) $row->quantity, $row->note,
            );
        }

        return $lines;
    }

    /**
     * TP1 total and external zakat declared for exactly this month.
     *
     * @return array{0: string, 1: string}
     */
    private function declarationsFor(int $employeeId, CarbonImmutable $period): array
    {
        $tp1 = '0';
        $zakat = '0';
        $rows = TaxDeclaration::query()->where('employee_id', $employeeId)->where('status', DocumentStatus::Submitted->value)
            ->whereDate('period', $period->toDateString())->with('lines')->get();
        foreach ($rows as $d) {
            if ($d->type === DeclarationType::Tp1) {
                foreach ($d->lines as $line) {
                    $tp1 = Num::add($tp1, (string) $line->amount);
                }
            } elseif ($d->type === DeclarationType::ZakatExternal) {
                $zakat = Num::add($zakat, (string) $d->zakat_amount);
            }
        }

        return [$tp1, $zakat];
    }

    /**
     * Formula variables describing the employee at period end (spec-04 §3.3).
     *
     * @return array<string, Number|bool|string>
     */
    public function attributes(Employee $employee, CarbonImmutable $periodEnd): array
    {
        $snap = EmployeeSnapshot::asAt($employee, $periodEnd);
        $service = Num::fixed(Num::div((string) $employee->serviceStart()->diffInDays($periodEnd), '365.25'), 2);

        return [
            'GRADE' => (string) ($snap->gradeId === null ? '' : Grade::query()->whereKey($snap->gradeId)->value('code')),
            'DESIGNATION' => (string) ($snap->designationId === null ? '' : (Designation::query()->whereKey($snap->designationId)->value('code') ?? '')),
            'DEPARTMENT' => (string) ($snap->departmentId === null ? '' : Department::query()->whereKey($snap->departmentId)->value('code')),
            'BRANCH' => (string) ($snap->branchId === null ? '' : Branch::query()->whereKey($snap->branchId)->value('code')),
            'EMPLOYMENT_CATEGORY' => (string) ($snap->employmentTypeId === null ? '' : (EmploymentType::query()->whereKey($snap->employmentTypeId)->first()?->category->value ?? '')),
            'CITIZENSHIP' => $employee->citizenship_status->value,
            'AGE' => new Number((string) $employee->ageOn($periodEnd)),
            'YEARS_OF_SERVICE' => new Number(Num::max($service, '0')),
        ];
    }

    /**
     * §4.1 identity rules: errors for missing IC / passport, warnings for missing numbers.
     *
     * @return list<string>
     */
    private function identityChecks(Employee $employee, CarbonImmutable $periodStart): array
    {
        $foreign = $employee->isForeign();
        if (! $foreign && ($employee->ic_no === null || $employee->ic_no === '')) {
            throw PayrollCalculationException::of('missing_ic');
        }
        if ($foreign && ($employee->passport_no === null || $employee->passport_no === '')) {
            throw PayrollCalculationException::of('missing_passport');
        }

        $warnings = [];
        if (($employee->getAttribute('kwsp_no') ?? '') === '' && ! $employee->kwsp_exempt) {
            $warnings[] = (string) __('payroll.warnings.missing_kwsp_no');
        }
        if (($employee->getAttribute('perkeso_no') ?? '') === '' && $foreign) {
            $warnings[] = (string) __('payroll.warnings.missing_perkeso_no');
        }
        if (($employee->taxProfile->tin ?? '') === '') {
            $warnings[] = (string) __('payroll.warnings.missing_tin');
        }
        if ($employee->salary_payment_mode === SalaryPaymentMode::BankTransfer
            && (($employee->getAttribute('bank_account_no') ?? '') === '' || $employee->getAttribute('bank_id') === null)) {
            $warnings[] = (string) __('payroll.warnings.missing_bank');
        }
        if ($foreign && $employee->work_pass_expiry === null) {
            $warnings[] = (string) __('payroll.warnings.missing_work_pass');
        }
        if ($foreign && $employee->work_pass_expiry !== null && $employee->work_pass_expiry->lessThan($periodStart->endOfMonth()->addDays(60))) {
            $warnings[] = (string) __('payroll.warnings.work_pass_expiring', ['date' => $employee->work_pass_expiry->format('d/m/Y')]);
        }

        return $warnings;
    }

    /** Malaysians (citizen / PR) employed in the month, for the HRD Corp headcount rule. */
    private function malaysianHeadcount(int $organisationId, CarbonImmutable $periodStart): int
    {
        $key = $organisationId.'-'.$periodStart->format('Ym');

        return $this->headcounts[$key] ??= Employee::query()
            ->whereIn('citizenship_status', [CitizenshipStatus::Citizen->value, CitizenshipStatus::PermanentResident->value])
            ->whereDate('date_of_joining', '<=', $periodStart->endOfMonth()->toDateString())
            ->where(fn ($q) => $q->whereNull('relieving_date')->orWhereDate('relieving_date', '>=', $periodStart->toDateString()))
            ->count();
    }
}
