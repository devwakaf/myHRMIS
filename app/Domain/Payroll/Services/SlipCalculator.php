<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\LineSource;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use App\Domain\Payroll\Enums\StatutoryKey;
use App\Domain\Payroll\Exceptions\PayrollCalculationException;
use App\Domain\Payroll\Formula\ArrayScope;
use App\Domain\Payroll\Formula\DependencyGraph;
use App\Domain\Payroll\Formula\Evaluator;
use App\Domain\Payroll\Formula\FormulaError;
use App\Domain\Payroll\Formula\FunctionRegistry;
use App\Domain\Payroll\Support\AdditionalLine;
use App\Domain\Payroll\Support\ComponentSpec;
use App\Domain\Payroll\Support\LineResult;
use App\Domain\Payroll\Support\SlipInput;
use App\Domain\Payroll\Support\SlipResult;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Shared\Support\DomainError;
use App\Domain\Statutory\Services\EisCalculator;
use App\Domain\Statutory\Services\EpfCalculator;
use App\Domain\Statutory\Services\HrdcLevyCalculator;
use App\Domain\Statutory\Services\MtdCalculator;
use App\Domain\Statutory\Services\SocsoCalculator;
use App\Domain\Statutory\Support\EisResult;
use App\Domain\Statutory\Support\EpfResult;
use App\Domain\Statutory\Support\MtdInput;
use App\Domain\Statutory\Support\MtdResult;
use App\Domain\Statutory\Support\SocsoResult;
use BcMath\Number;

/**
 * Pure slip calculation (spec-04 §4.3–§4.9): components in dependency order
 * (formulas, pro-ration, Additional Salary merge), statutory nodes (KWSP,
 * PERKESO/SKBBK, SIP, PCB, HRD levy) evaluated when the sort reaches them,
 * totals and the calculation trace. No database access; persistence is done by
 * GenerateSalarySlip.
 */
final class SlipCalculator
{
    public const G_GROSS = 'GROSS';

    public const G_LWP = '[CTG]';

    public const G_EPF = '[KWSP]';

    public const G_SOCSO = '[PERKESO]';

    public const G_EIS = '[SIP]';

    public const G_PCB = '[PCB]';

    public const G_HRDC = '[HRD]';

    /** Statutory key => group node that produces it. */
    private const KEY_GROUP = [
        'epf_employee' => self::G_EPF, 'epf_employer' => self::G_EPF,
        'socso_employee' => self::G_SOCSO, 'socso_employer' => self::G_SOCSO, 'skbbk_employee' => self::G_SOCSO,
        'eis_employee' => self::G_EIS, 'eis_employer' => self::G_EIS,
        'pcb' => self::G_PCB, 'hrdc_levy' => self::G_HRDC, 'lwp_deduction' => self::G_LWP,
    ];

    private const WAGE_FLAGS = ['epf_wage', 'socso_wage', 'eis_wage', 'hrdc_wage', 'taxable'];

    /** @var array<string, ComponentSpec> */
    private array $specs = [];

    /** @var array<string, string> code => actual (2 dp) */
    private array $actual = [];

    /** @var array<string, string> code => full cycle (unrounded) */
    private array $full = [];

    /** @var array<string, string> */
    private array $structureAmounts = [];

    /** @var array<string, string> */
    private array $additionalAmounts = [];

    /** @var array<string, list<array{id: int, amount: string}>> */
    private array $applications = [];

    /** @var array<string, list<AdditionalLine>> */
    private array $additionalByCode = [];

    /** @var array<string, bool> */
    private array $skipped = [];

    /** @var array<string, string> */
    private array $statutoryValues = [];

    /** @var array<string, string> exempt portion per earning code */
    private array $exempt = [];

    /** @var array<string, bool> union of wage flags carried by the separate LWP deduction */
    private array $lwpFlags = [];

    private string $lwpAmount = '0';

    private ?EpfResult $epf = null;

    private ?SocsoResult $socso = null;

    private ?EisResult $eis = null;

    private ?MtdResult $mtd = null;

    /** @var array{applies: bool, wage: string, rate: string, levy: string, rate_set_id: int|null}|null */
    private ?array $hrdc = null;

    private string $pcbMethod = 'none';

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, mixed> */
    private array $trace = [];

    private SlipInput $in;

    private ArrayScope $fullScope;

    private ArrayScope $actualScope;

    public function __construct(
        private readonly EpfCalculator $epfCalculator,
        private readonly SocsoCalculator $socsoCalculator,
        private readonly EisCalculator $eisCalculator,
        private readonly MtdCalculator $mtdCalculator,
        private readonly HrdcLevyCalculator $hrdcCalculator,
    ) {}

    public function calculate(SlipInput $in): SlipResult
    {
        $this->reset($in);
        $this->collectComponents();
        $order = $this->graph()->sort();
        $this->trace['order'] = $order;

        foreach ($order as $node) {
            match ($node) {
                self::G_GROSS => $this->evaluateGross(),
                self::G_LWP => $this->evaluateLwp(),
                self::G_EPF => $this->evaluateEpf(),
                self::G_SOCSO => $this->evaluateSocso(),
                self::G_EIS => $this->evaluateEis(),
                self::G_HRDC => $this->evaluateHrdc(),
                self::G_PCB => $this->evaluatePcb(),
                default => $this->evaluateComponent($this->specs[$node]),
            };
        }

        return $this->result();
    }

    // ------------------------------------------------------------------ setup

    private function reset(SlipInput $in): void
    {
        $this->in = $in;
        $this->specs = $this->actual = $this->full = $this->structureAmounts = $this->additionalAmounts = [];
        $this->applications = $this->additionalByCode = $this->skipped = $this->statutoryValues = $this->exempt = $this->lwpFlags = [];
        $this->lwpAmount = '0';
        $this->epf = $this->socso = $this->eis = $this->mtd = $this->hrdc = null;
        $this->pcbMethod = 'none';
        $this->warnings = [];
        $this->trace = ['days' => $in->days->toArray()];

        $d = $in->days;
        $common = [
            'BASE' => new Number(Num::of($in->base)),
            'CALENDAR_DAYS' => new Number($d->calendarDays),
            'WORKING_DAYS' => new Number($d->workingDays ?? '0'),
            'DIVISOR' => new Number($d->divisor),
            'PERIOD_MONTH' => new Number((string) $in->periodStart->month),
            'PERIOD_YEAR' => new Number((string) $in->periodStart->year),
            'IS_NEW_JOINER' => $d->isNewJoiner,
            'IS_LEAVER' => $d->isLeaver,
        ];
        $this->fullScope = new ArrayScope($in->attributes + $common + [
            'PAYMENT_FRACTION' => new Number('1'),
            'PAYMENT_DAYS' => new Number($d->divisor),
            'LWP_DAYS' => new Number('0'),
            'ABSENT_DAYS' => new Number('0'),
        ]);
        $this->actualScope = new ArrayScope($in->attributes + $common + [
            'PAYMENT_FRACTION' => new Number($d->fraction()),
            'PAYMENT_DAYS' => new Number($d->paymentDays()),
            'LWP_DAYS' => new Number($d->lwpDays),
            'ABSENT_DAYS' => new Number($d->absentDays),
        ]);
    }

    private function collectComponents(): void
    {
        foreach ($this->in->components as $spec) {
            $this->specs[$spec->code] = $spec;
        }

        $extrasById = [];
        foreach ($this->in->extraComponents as $extra) {
            $extrasById[$extra->id] = $extra;
        }
        $byId = [];
        foreach ($this->specs as $spec) {
            $byId[$spec->id] = $spec;
        }

        foreach ($this->in->additional as $line) {
            $spec = $byId[$line->componentId] ?? null;
            if ($spec === null) {
                $extra = $extrasById[$line->componentId] ?? throw PayrollCalculationException::of('additional_component_unknown', ['id' => $line->componentId]);
                $source = match ($line->source) {
                    AdditionalSource::OvertimeClaim => LineSource::Overtime,
                    AdditionalSource::LeaveEncashment => LineSource::LeaveEncashment,
                    default => LineSource::Additional,
                };
                $spec = $extra->with(method: $extra->isStatutory() ? CalculationMethod::Input : null, source: $source);
                $this->specs[$spec->code] = $spec;
                $byId[$spec->id] = $spec;
            }
            $this->additionalByCode[$spec->code][] = $line;
        }

        foreach ($this->additionalByCode as $code => $lines) {
            $replaces = array_filter($lines, fn (AdditionalLine $l) => $l->mode === AdditionalMode::Replace);
            if (count($replaces) > 1) {
                throw PayrollCalculationException::of('replace_conflict', ['code' => $code]);
            }
        }

        if ($this->in->lwpPresentation === 'separate_deduction'
            && Num::cmp($this->in->days->paidNumerator(), $this->in->days->spanNumerator()) < 0) {
            foreach ($this->in->extraComponents as $extra) {
                if ($extra->statutoryKey === StatutoryKey::LwpDeduction && ! isset($this->specs[$extra->code])) {
                    $this->specs[$extra->code] = $extra->with(source: LineSource::LwpDeduction);
                }
            }
        }
    }

    private function graph(): DependencyGraph
    {
        $graph = new DependencyGraph;
        $groups = $this->neededGroups();
        $referencesGross = false;

        foreach ($this->specs as $code => $spec) {
            $graph->addNode($code, $spec->sortKey());
        }
        foreach ($groups as $group) {
            $graph->addNode($group, '5-'.$group);
        }

        foreach ($this->specs as $code => $spec) {
            $group = $spec->statutoryKey !== null ? (self::KEY_GROUP[$spec->statutoryKey->value] ?? null) : null;
            if ($group !== null && in_array($group, $groups, true) && ($spec->isStatutory() || $group === self::G_LWP)) {
                $graph->addEdge($code, $group);

                continue;
            }
            foreach ($spec->references() as $ref) {
                if ($ref === self::G_GROSS) {
                    $referencesGross = true;
                    $graph->addEdge($code, self::G_GROSS);
                } elseif (isset($this->specs[$ref])) {
                    $graph->addEdge($code, $ref);
                }
            }
        }

        if ($referencesGross) {
            $graph->addNode(self::G_GROSS, '1-999999-GROSS');
            foreach ($this->specs as $code => $spec) {
                if ($spec->kind === ComponentKind::Earning && $spec->flag('include_in_gross')) {
                    $graph->addEdge(self::G_GROSS, $code);
                }
            }
        }

        foreach ($groups as $group) {
            foreach ($this->specs as $code => $spec) {
                if ($this->groupDependsOn($group, $spec)) {
                    $graph->addEdge($group, $code);
                }
            }
            if ($group !== self::G_LWP && in_array(self::G_LWP, $groups, true)) {
                $graph->addEdge($group, self::G_LWP);
            }
        }
        if (in_array(self::G_PCB, $groups, true)) {
            foreach ([self::G_EPF, self::G_SOCSO, self::G_EIS] as $dep) {
                if (in_array($dep, $groups, true) && ($dep === self::G_EPF || ($this->in->statutory->mtd->autoSocsoEisRelief ?? false))) {
                    $graph->addEdge(self::G_PCB, $dep);
                }
            }
        }

        return $graph;
    }

    /**
     * @return list<string>
     */
    private function neededGroups(): array
    {
        $groups = [];
        foreach ($this->specs as $spec) {
            if ($spec->statutoryKey !== null && $spec->isStatutory()) {
                $groups[] = self::KEY_GROUP[$spec->statutoryKey->value] ?? null;
            }
            if ($spec->statutoryKey === StatutoryKey::LwpDeduction) {
                $groups[] = self::G_LWP;
            }
        }
        if (in_array(self::G_PCB, $groups, true)) {
            $groups[] = self::G_EPF;
        }

        return array_values(array_unique(array_filter($groups)));
    }

    private function groupDependsOn(string $group, ComponentSpec $spec): bool
    {
        if ($spec->isStatutory() || $spec->statutoryKey === StatutoryKey::LwpDeduction) {
            return false;
        }
        $wageReduction = $spec->kind === ComponentKind::Deduction && $spec->flag('is_wage_reduction');
        $earning = $spec->kind === ComponentKind::Earning;

        return match ($group) {
            self::G_LWP => $earning && $spec->prorate,
            self::G_EPF => ($earning || $wageReduction) && $spec->flag('epf_wage'),
            self::G_SOCSO => ($earning || $wageReduction) && $spec->flag('socso_wage'),
            self::G_EIS => ($earning || $wageReduction) && $spec->flag('eis_wage'),
            self::G_HRDC => ($earning || $wageReduction) && $spec->flag('hrdc_wage'),
            self::G_PCB => (($earning || $wageReduction) && $spec->flag('taxable'))
                || $spec->statutoryKey === StatutoryKey::Zakat || $spec->statutoryKey === StatutoryKey::Cp38,
            default => false,
        };
    }

    // ------------------------------------------------------------------ components

    private function evaluateComponent(ComponentSpec $spec): void
    {
        $code = $spec->code;
        $evaluator = new Evaluator($code);

        if ($spec->condition !== null && ! $evaluator->condition($spec->condition, $this->actualScope)) {
            $this->skipped[$code] = true;
            $this->store($code, '0', '0.00');

            return;
        }

        if ($spec->statutoryKey === StatutoryKey::LwpDeduction) {
            $value = $this->lwpAmount;
            $this->structureAmounts[$code] = $value;
            $this->store($code, $value, $value);

            return;
        }

        if ($spec->isStatutory()) {
            $value = $this->statutoryValues[$code] ?? '0.00';
            $this->structureAmounts[$code] = $value;
            $this->store($code, $value, $value);

            return;
        }

        $full = match ($spec->method) {
            CalculationMethod::Formula => $spec->formula === null ? '0' : (string) $evaluator->number($spec->formula, $this->fullScope),
            CalculationMethod::Fixed, CalculationMethod::Statutory => Num::of($spec->amount),
            CalculationMethod::Input => '0',
        };
        $this->assertNotNegative($code, $full);

        if ($spec->prorate && $spec->method !== CalculationMethod::Input) {
            $structure = $spec->rounding->apply($this->in->days->prorate($full));
            if ($this->in->lwpPresentation === 'separate_deduction' && $spec->kind === ComponentKind::Earning) {
                $shown = $spec->rounding->apply($this->in->days->prorateSpan($full));
                $difference = Num::sub($shown, $structure);
                if (Num::isPositive($difference)) {
                    $this->lwpAmount = Num::add($this->lwpAmount, $difference);
                    foreach (self::WAGE_FLAGS as $flag) {
                        $this->lwpFlags[$flag] = ($this->lwpFlags[$flag] ?? false) || $spec->flag($flag);
                    }
                }
                $structure = $shown;
            }
        } elseif ($spec->method === CalculationMethod::Formula && $spec->formula !== null) {
            $structure = $spec->rounding->apply((string) $evaluator->number($spec->formula, $this->actualScope));
        } else {
            $structure = $spec->rounding->apply($full);
        }
        $this->assertNotNegative($code, $structure);
        $this->structureAmounts[$code] = $structure;

        $actual = $structure;
        $added = '0';
        foreach ($this->additionalByCode[$code] ?? [] as $line) {
            $amount = $line->prorate ? Num::money($this->in->days->prorate($line->amount)) : Num::money($line->amount);
            if ($line->mode === AdditionalMode::Replace) {
                $actual = Num::add(Num::sub($actual, $structure), $amount);
            } else {
                $actual = Num::add($actual, $amount);
                $added = Num::add($added, $amount);
            }
            if ($line->additionalSalaryId !== null) {
                $this->applications[$code][] = ['id' => $line->additionalSalaryId, 'amount' => $amount];
            }
        }
        $this->additionalAmounts[$code] = Num::money($added);
        $actual = Num::money($actual);
        $this->assertNotNegative($code, $actual);

        $this->store($code, $full, $actual);
    }

    private function store(string $code, string $full, string $actual): void
    {
        $this->full[$code] = $full;
        $this->actual[$code] = $actual;
        $this->fullScope->set($code, new Number(Num::of($full)));
        $this->fullScope->setFull($code, new Number(Num::of($full)));
        $this->actualScope->set($code, new Number(Num::of($actual)));
        $this->actualScope->setFull($code, new Number(Num::of($full)));
    }

    private function assertNotNegative(string $code, string $value): void
    {
        if (Num::isNegative($value)) {
            throw FormulaError::of('negative_result', ['code' => $code, 'value' => Num::money($value)]);
        }
    }

    private function evaluateGross(): void
    {
        $actual = '0';
        $full = '0';
        foreach ($this->specs as $code => $spec) {
            if ($spec->kind === ComponentKind::Earning && $spec->flag('include_in_gross')) {
                $actual = Num::add($actual, $this->actual[$code] ?? '0');
                $full = Num::add($full, $this->full[$code] ?? '0');
            }
        }
        $this->fullScope->set(self::G_GROSS, new Number($full));
        $this->actualScope->set(self::G_GROSS, new Number($actual));
    }

    private function evaluateLwp(): void
    {
        // The deduction line itself is filled by evaluateComponent (POTONGAN_CTG depends on this node).
        $this->trace['lwp_deduction'] = ['amount' => Num::money($this->lwpAmount), 'flags' => $this->lwpFlags];
    }

    // ------------------------------------------------------------------ statutory

    /** Σ actual of earnings with $flag minus wage-reduction deductions carrying it. */
    private function wageBase(string $flag, ?PcbRemunerationType $pcbType = null, bool $excludeBonus = false): string
    {
        $sum = '0';
        foreach ($this->specs as $code => $spec) {
            if (($this->skipped[$code] ?? false) || ! isset($this->actual[$code])) {
                continue;
            }
            if ($pcbType !== null && $spec->pcbType !== $pcbType) {
                continue;
            }
            $isLwp = $spec->statutoryKey === StatutoryKey::LwpDeduction;
            $hasFlag = $isLwp ? ($this->lwpFlags[$flag] ?? false) : $spec->flag($flag);
            if (! $hasFlag) {
                continue;
            }
            if ($spec->kind === ComponentKind::Earning) {
                if ($excludeBonus && $spec->flag('is_epf_bonus')) {
                    continue;
                }
                $amount = $this->actual[$code];
                if ($flag === 'taxable') {
                    $amount = Num::sub($amount, $this->exemptFor($spec, $amount));
                }
                $sum = Num::add($sum, $amount);
            } elseif ($spec->kind === ComponentKind::Deduction && ($isLwp || $spec->flag('is_wage_reduction'))) {
                $sum = Num::sub($sum, $this->actual[$code]);
            }
        }

        return Num::max($sum, '0');
    }

    private function codeFor(StatutoryKey $key): ?string
    {
        foreach ($this->specs as $code => $spec) {
            if ($spec->statutoryKey === $key) {
                return $code;
            }
        }

        return null;
    }

    private function setStatutory(StatutoryKey $key, string $value): void
    {
        $code = $this->codeFor($key);
        if ($code !== null) {
            $this->statutoryValues[$code] = Num::money($value);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function guarded(string $scheme, ?object $table, callable $callback): mixed
    {
        if ($table === null) {
            if ($this->in->statutoryErrorsAsWarnings) {
                $this->warnings[] = (string) __('payroll.warnings.no_rate_set', ['scheme' => $scheme]);

                return null;
            }

            throw PayrollCalculationException::of('no_rate_set', ['scheme' => $scheme, 'date' => $this->in->periodEnd->format('d/m/Y')]);
        }

        try {
            return $callback();
        } catch (DomainError $e) {
            if ($this->in->statutoryErrorsAsWarnings && ! $e instanceof FormulaError) {
                $this->warnings[] = $e->userMessage();

                return null;
            }

            throw $e;
        }
    }

    private function evaluateEpf(): void
    {
        $s = $this->in->statutory;
        $wage = $this->wageBase('epf_wage');
        $wageNoBonus = $this->wageBase('epf_wage', excludeBonus: true);
        $wageNormal = $this->wageBase('epf_wage', PcbRemunerationType::Normal);

        $this->epf = $this->guarded('KWSP', $s->epf, fn () => $this->epfCalculator->calculate(
            $s->epf ?? throw new \LogicException('epf table'), $s->person, $wage, $wageNoBonus, $wageNormal, $s->epfEmployeeRateOverride, $s->epfEmployerRateOverride,
        ));
        if ($this->epf === null) {
            return;
        }
        array_push($this->warnings, ...$this->epf->warnings);
        $this->setStatutory(StatutoryKey::EpfEmployee, $this->epf->employee);
        $this->setStatutory(StatutoryKey::EpfEmployer, $this->epf->employer);
        $this->trace['epf'] = [
            'part' => $this->epf->part->value, 'method' => $this->epf->method, 'wage' => $this->epf->wage,
            'wage_excl_bonus' => $this->epf->wageExclBonus, 'wage_normal' => Num::money($wageNormal),
            'employee' => $this->epf->employee, 'employer' => $this->epf->employer, 'employee_normal' => $this->epf->employeeNormal,
            'rate_set_id' => $this->epf->rateSetId, 'detail' => $this->epf->trace,
        ];
    }

    private function evaluateSocso(): void
    {
        $s = $this->in->statutory;
        $wage = $this->wageBase('socso_wage');
        $this->socso = $this->guarded('PERKESO', $s->socso, fn () => $this->socsoCalculator->calculate(
            $s->socso ?? throw new \LogicException('socso table'), $s->person, $wage,
        ));
        if ($this->socso === null) {
            return;
        }
        $this->setStatutory(StatutoryKey::SocsoEmployee, $this->socso->employee);
        $this->setStatutory(StatutoryKey::SocsoEmployer, $this->socso->employer);
        $this->setStatutory(StatutoryKey::SkbbkEmployee, $this->socso->skbbkEmployee);
        $this->trace['socso'] = [
            'category' => $this->socso->category->value, 'wage' => $this->socso->wage, 'band' => $this->socso->band,
            'employer' => $this->socso->employer, 'employee' => $this->socso->employee, 'skbbk_applies' => $this->socso->skbbkApplies,
            'skbbk_employee' => $this->socso->skbbkEmployee, 'rate_set_id' => $this->socso->rateSetId,
        ];
    }

    private function evaluateEis(): void
    {
        $s = $this->in->statutory;
        $wage = $this->wageBase('eis_wage');
        $this->eis = $this->guarded('SIP', $s->eis, fn () => $this->eisCalculator->calculate(
            $s->eis ?? throw new \LogicException('eis table'), $s->person, $wage,
        ));
        if ($this->eis === null) {
            return;
        }
        $this->setStatutory(StatutoryKey::EisEmployee, $this->eis->employee);
        $this->setStatutory(StatutoryKey::EisEmployer, $this->eis->employer);
        $this->trace['eis'] = [
            'eligible' => $this->eis->eligible, 'wage' => $this->eis->wage, 'band' => $this->eis->band,
            'employer' => $this->eis->employer, 'employee' => $this->eis->employee, 'rate_set_id' => $this->eis->rateSetId,
        ];
    }

    private function evaluateHrdc(): void
    {
        $s = $this->in->statutory;
        $wage = $this->wageBase('hrdc_wage');
        $this->hrdc = $this->hrdcCalculator->calculate($s->hrdc, $s->hrdcRegistered, $s->hrdcRate, $s->headcount, $s->person->citizenship->value, $wage);
        $this->setStatutory(StatutoryKey::HrdcLevy, $this->hrdc['levy']);
        $this->trace['hrdc'] = $this->hrdc;
    }

    private function evaluatePcb(): void
    {
        $s = $this->in->statutory;
        $y1 = $this->wageBase('taxable', PcbRemunerationType::Normal);
        $yt = $this->wageBase('taxable', PcbRemunerationType::Additional);
        $zakat = $this->sumKey(StatutoryKey::Zakat);

        if ($s->pcbOverride !== null) {
            $this->pcbMethod = 'override';
            $this->setStatutory(StatutoryKey::Pcb, $s->pcbOverride);
            $this->warnings[] = (string) __('payroll.warnings.pcb_override');
            $this->trace['pcb'] = ['method' => 'override', 'amount' => Num::money($s->pcbOverride), 'Y1' => Num::trim($y1), 'Yt' => Num::trim($yt)];

            return;
        }

        if ($s->mtdScheme !== 'normal') {
            throw PayrollCalculationException::of('unsupported_scheme', ['scheme' => $s->mtdScheme]);
        }

        $k1 = $this->epf->employeeNormal ?? '0';
        $kt = Num::max(Num::sub($this->epf->employee ?? '0', $k1), '0');
        $y2 = $s->y2Basis === 'full_cycle' ? $this->fullTaxableNormal() : null;
        $socsoEis = Num::add($this->socso->employee ?? '0', $this->eis->employee ?? '0');

        $input = new MtdInput(
            category: $s->pcbCategory,
            resident: $s->resident,
            month: $this->in->periodStart->month,
            y: $s->ytd->y(),
            k: $s->ytd->k,
            x: $s->ytd->x,
            z: $s->ytd->z,
            lp: $s->ytd->lp,
            y1: $y1,
            k1: $k1,
            yt: $yt,
            kt: $kt,
            lp1: $s->tp1ThisMonth,
            y2: $y2,
            disabledSelf: $s->disabledSelf,
            disabledSpouse: $s->spouseDisabled,
            claimsChildRelief: $s->claimsChildRelief,
            childrenBasic: $s->childrenBasic,
            childrenHigherEd: $s->childrenHigherEd,
            childrenDisabled: $s->childrenDisabled,
            childrenDisabledHigherEd: $s->childrenDisabledHigherEd,
            zakatPayroll: $zakat,
            zakatExternal: $s->zakatExternal,
            socsoEisEmployee: $socsoEis,
            socsoEisPrior: Num::add($s->ytd->socsoEmployee, $s->ytd->eisEmployee),
        );

        $this->mtd = $this->guarded('PCB', $s->mtd, fn () => $this->mtdCalculator->calculate($s->mtd ?? throw new \LogicException('mtd table'), $input));
        if ($this->mtd === null) {
            return;
        }
        $this->pcbMethod = $this->mtd->method;
        $this->setStatutory(StatutoryKey::Pcb, $this->mtd->pcbNet);
        $this->trace['pcb'] = ['method' => $this->mtd->method, 'inputs' => $this->mtd->inputs, 'pcb_net' => $this->mtd->pcbNet, 'rate_set_id' => $this->mtd->rateSetId];
    }

    private function fullTaxableNormal(): string
    {
        $sum = '0';
        foreach ($this->specs as $code => $spec) {
            if ($spec->kind === ComponentKind::Earning && $spec->flag('taxable') && $spec->pcbType === PcbRemunerationType::Normal && ! ($this->skipped[$code] ?? false)) {
                $sum = Num::add($sum, $this->full[$code] ?? '0');
            }
        }

        return $sum;
    }

    private function sumKey(StatutoryKey $key): string
    {
        $sum = '0';
        foreach ($this->specs as $code => $spec) {
            if ($spec->statutoryKey === $key) {
                $sum = Num::add($sum, $this->actual[$code] ?? '0');
            }
        }

        return Num::money($sum);
    }

    /** Exempt part of a taxable earning within the remaining annual cap (spec-04 §4.6 5e step 1). */
    private function exemptFor(ComponentSpec $spec, string $amount): string
    {
        if (isset($this->exempt[$spec->code])) {
            return $this->exempt[$spec->code];
        }

        $codeKey = $spec->taxExemptionCode;
        $caps = $this->in->statutory->reliefs->exemptionCaps ?? [];
        if ($codeKey === null || ! array_key_exists($codeKey, $caps)) {
            return $this->exempt[$spec->code] = '0.00';
        }

        $cap = $caps[$codeKey];
        if ($cap === null) {
            return $this->exempt[$spec->code] = Num::money($amount);
        }

        $used = $this->in->statutory->ytd->taxExemptUsed[$codeKey] ?? '0';
        foreach ($this->exempt as $otherCode => $value) {
            if (($this->specs[$otherCode]->taxExemptionCode ?? null) === $codeKey) {
                $used = Num::add($used, $value);
            }
        }

        return $this->exempt[$spec->code] = Num::money(Num::min($amount, Num::max(Num::sub($cap, $used), '0')));
    }

    // ------------------------------------------------------------------ result

    private function result(): SlipResult
    {
        $lines = [];
        $gross = '0';
        $deductions = '0';
        $employer = '0';

        foreach ($this->specs as $code => $spec) {
            if ($this->skipped[$code] ?? false) {
                continue;
            }
            $amount = Num::money($this->actual[$code] ?? '0');
            $statistical = $spec->kind === ComponentKind::Statistical;
            if (! $statistical && Num::isZero($amount) && ! $spec->showIfZero) {
                continue;
            }

            $flags = $spec->flags;
            if ($spec->statutoryKey === StatutoryKey::LwpDeduction) {
                $flags = array_merge($flags, $this->lwpFlags, ['is_wage_reduction' => true]);
            }

            $quantity = null;
            $note = null;
            $adds = $this->additionalByCode[$code] ?? [];
            foreach ($adds as $line) {
                if ($line->quantity !== null) {
                    $quantity = Num::add($quantity ?? '0', $line->quantity);
                }
                if ($line->note !== null) {
                    $note = $note === null ? $line->note : $note.'; '.$line->note;
                }
            }
            if ($spec->statutoryKey === StatutoryKey::LwpDeduction) {
                $quantity = Num::add($this->in->days->lwpDays, $this->in->days->absentDays, $this->in->days->unmarkedCounted ? $this->in->days->unmarkedDays : '0');
            }

            $lines[] = new LineResult(
                $spec, $amount, Num::money($this->full[$code] ?? '0'), Num::money($this->structureAmounts[$code] ?? '0'),
                Num::money($this->additionalAmounts[$code] ?? '0'), $spec->source, $flags, $this->applications[$code] ?? [],
                $quantity === null ? null : Num::fixed($quantity, 3),
                $spec->kind === ComponentKind::Earning && $spec->flag('taxable') ? ($this->exempt[$code] ?? '0.00') : '0.00',
                $note, ! $statistical && $spec->flag('show_on_slip'),
            );

            match ($spec->kind) {
                ComponentKind::Earning => $gross = $spec->flag('include_in_gross') ? Num::add($gross, $amount) : $gross,
                ComponentKind::Deduction => $deductions = Num::add($deductions, $amount),
                ComponentKind::EmployerContribution => $employer = Num::add($employer, $amount),
                ComponentKind::Statistical => null,
            };
        }

        usort($lines, fn (LineResult $a, LineResult $b) => [$a->component->kind->displayOrder(), $a->component->sortOrder, $a->component->code]
            <=> [$b->component->kind->displayOrder(), $b->component->sortOrder, $b->component->code]);

        $net = Num::money(Num::sub($gross, $deductions));
        $adjustment = '0.00';
        if ($this->in->netPayRounding === 'nearest_ringgit') {
            $rounded = (string) Rounding::toStep($net, '1', \RoundingMode::HalfAwayFromZero, 2);
            $adjustment = Num::money(Num::sub($rounded, $net));
            $net = $rounded;
        }

        $hasErrors = Num::isNegative($net);
        if ($hasErrors) {
            $this->warnings[] = (string) __('payroll.warnings.negative_net');
        }

        $this->trace['lines'] = array_map(fn (LineResult $l) => [
            'code' => $l->component->code, 'kind' => $l->component->kind->value, 'full' => $l->fullCycle, 'structure' => $l->structureAmount,
            'additional' => $l->additionalAmount, 'amount' => $l->amount, 'exempt' => $l->exemptAmount,
        ], $lines);

        $pcbCode = $this->codeFor(StatutoryKey::Pcb);

        return new SlipResult(
            $lines, $this->epf, $this->socso, $this->eis, $this->mtd, $this->hrdc,
            $this->sumKey(StatutoryKey::Zakat), Num::money($this->in->statutory->zakatExternal), $this->sumKey(StatutoryKey::Cp38),
            $pcbCode === null ? '0.00' : Num::money($this->actual[$pcbCode] ?? '0'), $this->pcbMethod,
            Num::money($gross), Num::money($deductions), $net, $adjustment, Num::money($employer),
            array_values(array_unique($this->warnings)), $this->trace, $hasErrors,
        );
    }

    /** Reserved word check used by component validation. */
    public static function isReservedCode(string $code): bool
    {
        return FunctionRegistry::isReserved($code) || in_array($code, [self::G_GROSS], true);
    }
}
