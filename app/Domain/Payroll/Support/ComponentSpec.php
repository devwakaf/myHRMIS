<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\EaBox;
use App\Domain\Payroll\Enums\LineSource;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use App\Domain\Payroll\Enums\RoundingMode;
use App\Domain\Payroll\Enums\StatutoryKey;
use App\Domain\Payroll\Formula\Ast\Node;
use App\Domain\Payroll\Formula\References;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryStructureRow;

/**
 * A component as it takes part in one calculation: the component master merged
 * with its structure row and any assignment override (spec-04 §2.3–§2.4, §3.4).
 */
final class ComponentSpec
{
    /**
     * @param  array<string, bool>  $flags  include_in_gross, epf_wage, socso_wage, eis_wage, hrdc_wage, taxable, is_epf_bonus, ot_wage_base, is_wage_reduction, show_on_slip
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $nameMs,
        public readonly string $nameEn,
        public readonly ComponentKind $kind,
        public readonly CalculationMethod $method,
        public readonly ?StatutoryKey $statutoryKey,
        public readonly ?string $amount,
        public readonly ?Node $formula,
        public readonly ?Node $condition,
        public readonly bool $prorate,
        public readonly array $flags,
        public readonly PcbRemunerationType $pcbType,
        public readonly EaBox $eaBox,
        public readonly RoundingMode $rounding,
        public readonly int $sortOrder,
        public readonly bool $showIfZero,
        public readonly ?string $taxExemptionCode,
        public readonly bool $arrearEligible,
        public readonly LineSource $source,
        public readonly bool $inStructure,
    ) {}

    public static function fromComponent(SalaryComponent $c, ?SalaryStructureRow $row = null, ?string $overrideAmount = null, int $position = 0): self
    {
        $method = $row->calculation_override ?? $c->calculation;
        $formulaAst = $row->formula_ast ?? $c->default_formula_ast;
        $conditionAst = $row->condition_ast ?? $c->default_condition_ast;
        $amount = $row->amount ?? $c->default_amount;
        $source = $c->statutory_key !== null && $method === CalculationMethod::Statutory ? LineSource::Statutory : LineSource::Structure;

        if ($overrideAmount !== null) {
            $method = CalculationMethod::Fixed;
            $amount = $overrideAmount;
            $formulaAst = null;
            $source = LineSource::AssignmentOverride;
        }

        $flags = [];
        foreach (SalaryComponent::LINE_FLAGS as $flag) {
            $flags[$flag] = (bool) $c->getAttribute($flag);
        }
        if ($c->kind === ComponentKind::Statistical) {
            $flags['show_on_slip'] = false;
        }

        return new self(
            $c->id, $c->code, $c->name_ms, $c->name_en, $c->kind, $method, $c->statutory_key,
            $amount === null ? null : (string) $amount,
            $method === CalculationMethod::Formula && $formulaAst !== null ? Node::fromArray($formulaAst) : null,
            $conditionAst !== null ? Node::fromArray($conditionAst) : null,
            $row->prorate_override ?? $c->prorate,
            $flags, $c->pcb_type, $c->ea_box, $c->rounding_mode,
            $row !== null ? $position : 1000 + $c->sort_order,
            $c->show_if_zero, $c->tax_exemption_code, $c->arrear_eligible, $source, $row !== null,
        );
    }

    public function with(?string $amount = null, ?CalculationMethod $method = null, ?LineSource $source = null): self
    {
        return new self(
            $this->id, $this->code, $this->nameMs, $this->nameEn, $this->kind, $method ?? $this->method, $this->statutoryKey,
            $amount ?? $this->amount, $this->formula, $this->condition, $this->prorate, $this->flags, $this->pcbType, $this->eaBox,
            $this->rounding, $this->sortOrder, $this->showIfZero, $this->taxExemptionCode, $this->arrearEligible,
            $source ?? $this->source, $this->inStructure,
        );
    }

    public function flag(string $name): bool
    {
        return $this->flags[$name] ?? false;
    }

    public function isStatutory(): bool
    {
        return $this->method === CalculationMethod::Statutory && $this->statutoryKey !== null;
    }

    /**
     * Component codes / variables this spec reads (formula + condition).
     *
     * @return list<string>
     */
    public function references(): array
    {
        return array_values(array_unique([...References::of($this->formula)->all(), ...References::of($this->condition)->all()]));
    }

    public function sortKey(): string
    {
        return sprintf('%d-%06d-%s', $this->kind->evaluationOrder(), $this->sortOrder, $this->code);
    }
}
