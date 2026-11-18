<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\EaBox;
use App\Domain\Payroll\Enums\StatutoryKey;
use App\Domain\Payroll\Formula\FormulaError;
use App\Domain\Payroll\Formula\FunctionRegistry;
use App\Domain\Payroll\Formula\Parser;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Shared\Support\DomainValidation;

/**
 * Komponen Gaji save rules (spec-04 §2.3): code format, reserved words,
 * immutability once used, system rows keep kind/key, wage and tax flags only on
 * earnings (or wage reductions), formula only with the formula method.
 */
final class ComponentActions
{
    private const WAGE_FLAGS = ['epf_wage', 'socso_wage', 'eis_wage', 'hrdc_wage', 'taxable', 'is_epf_bonus', 'ot_wage_base'];

    /**
     * @param  array<string, mixed>  $data
     * @return array{component: SalaryComponent, warnings: list<string>}
     */
    public function save(?SalaryComponent $component, array $data): array
    {
        $errors = [];
        $code = strtoupper(trim((string) ($data['code'] ?? $component->code ?? '')));
        $kind = ComponentKind::tryFrom((string) ($data['kind'] ?? $component?->kind->value ?? ''));
        $method = CalculationMethod::tryFrom((string) ($data['calculation'] ?? $component?->calculation->value ?? ''));
        $key = array_key_exists('statutory_key', $data) ? StatutoryKey::tryFrom((string) $data['statutory_key']) : $component?->statutory_key;

        if (preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $code) !== 1) {
            $errors['code'] = (string) __('payroll.errors.code_format');
        } elseif (FunctionRegistry::isReserved($code)) {
            $errors['code'] = (string) __('payroll.formula.reserved_code', ['code' => $code]);
        } elseif (SalaryComponent::query()->where('code', $code)->when($component !== null, fn ($q) => $q->whereKeyNot($component?->id))->exists()) {
            $errors['code'] = (string) __('payroll.errors.code_taken');
        }
        if ($component !== null && $component->first_used_at !== null && $code !== $component->code) {
            $errors['code'] = (string) __('payroll.errors.code_immutable');
        }
        if ($component !== null && $component->is_system && ($kind !== $component->kind || $key !== $component->statutory_key)) {
            $errors['kind'] = (string) __('payroll.errors.system_component_locked');
        }
        if ($kind === null) {
            $errors['kind'] = (string) __('payroll.errors.kind_required');
        }
        if ($method === null) {
            $errors['calculation'] = (string) __('payroll.errors.method_required');
        } elseif ($method === CalculationMethod::Statutory && $key === null) {
            $errors['calculation'] = (string) __('payroll.errors.statutory_needs_key');
        }
        if ($key !== null && SalaryComponent::query()->where('statutory_key', $key->value)->when($component !== null, fn ($q) => $q->whereKeyNot($component?->id))->exists()) {
            $errors['statutory_key'] = (string) __('payroll.errors.statutory_key_taken');
        }

        $isReduction = (bool) ($data['is_wage_reduction'] ?? $component->is_wage_reduction ?? false);
        if ($kind !== null && $kind !== ComponentKind::Earning && ! $isReduction) {
            foreach (self::WAGE_FLAGS as $flag) {
                if ((bool) ($data[$flag] ?? false)) {
                    $errors[$flag] = (string) __('payroll.errors.flag_needs_earning');
                }
            }
        }

        $formula = trim((string) ($data['default_formula'] ?? $component->default_formula ?? ''));
        $condition = trim((string) ($data['default_condition'] ?? $component->default_condition ?? ''));
        $formulaAst = null;
        $conditionAst = null;
        if ($formula !== '' && $method !== CalculationMethod::Formula) {
            $errors['default_formula'] = (string) __('payroll.formula.formula_method_mismatch', ['code' => $code]);
        }
        try {
            $formulaAst = $formula === '' ? null : (new Parser)->parse($formula)->toArray();
            $conditionAst = $condition === '' ? null : (new Parser)->parse($condition)->toArray();
        } catch (FormulaError $e) {
            $errors['default_formula'] = $e->userMessage();
        }
        DomainValidation::throwIf($errors);

        $values = array_intersect_key($data, array_flip([
            'name_ms', 'name_en', 'default_amount', 'prorate', 'include_in_gross', 'show_on_slip', 'show_if_zero', 'epf_wage',
            'socso_wage', 'eis_wage', 'hrdc_wage', 'taxable', 'pcb_type', 'tax_exemption_code', 'ea_box', 'is_epf_bonus',
            'ot_wage_base', 'arrear_eligible', 'is_wage_reduction', 'arrear_component_id', 'rounding_mode', 'sort_order',
            'description_ms', 'description_en', 'is_active',
        ]));
        $values += [
            'code' => $code, 'kind' => $kind, 'calculation' => $method, 'statutory_key' => $key,
            'default_formula' => $formula === '' ? null : $formula, 'default_condition' => $condition === '' ? null : $condition,
            'default_formula_ast' => $formulaAst, 'default_condition_ast' => $conditionAst,
        ];
        if ($kind === ComponentKind::Statistical) {
            $values['show_on_slip'] = false;
        }

        $component ??= new SalaryComponent;
        $component->fill($values)->save();

        $warnings = [];
        if ($component->kind === ComponentKind::Earning && $component->taxable && $component->ea_box === EaBox::None) {
            $warnings[] = (string) __('payroll.warnings.ea_box_missing', ['code' => $component->code]);
        }

        return ['component' => $component, 'warnings' => $warnings];
    }
}
