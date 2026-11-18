<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Formula\FormulaError;
use App\Domain\Payroll\Formula\FunctionRegistry;
use App\Domain\Payroll\Formula\Parser;
use App\Domain\Payroll\Formula\References;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Models\SalaryStructureRow;
use App\Domain\Payroll\Services\StructureDryRun;
use App\Domain\Payroll\Support\ComponentSpec;
use App\Domain\Payroll\Support\SlipResult;
use App\Domain\Shared\Support\DomainValidation;
use Illuminate\Support\Facades\DB;

/**
 * Struktur Gaji save (spec-04 §3.5–§3.6): parse every formula and condition,
 * static checks (unknown identifier, self reference, GROSS in an earning, double
 * pro-ration, method/formula mismatch), then the dependency sort with cycle
 * detection and the dry run. Any error blocks the save.
 */
final class StructureActions
{
    public function __construct(private readonly StructureDryRun $dryRun) {}

    /**
     * @param  array<string, mixed>  $data  code, name_ms, name_en, description, is_active, rows[]
     * @return array{structure: SalaryStructure, dry_run: SlipResult, warnings: list<string>}
     */
    public function save(?SalaryStructure $structure, array $data, ?Employee $testEmployee = null): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if (preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $code) !== 1) {
            throw DomainValidation::withMessages(['code' => (string) __('payroll.errors.code_format')]);
        }
        $duplicate = SalaryStructure::query()->where('code', $code)->when($structure !== null, fn ($q) => $q->whereKeyNot($structure?->id))->exists();
        if ($duplicate) {
            throw DomainValidation::withMessages(['code' => (string) __('payroll.errors.code_taken')]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values((array) ($data['rows'] ?? []));
        [$prepared, $specs] = $this->validateRows($rows);

        try {
            $result = $this->dryRun->run($specs, $testEmployee);
        } catch (FormulaError $e) {
            throw DomainValidation::withMessages(['rows' => $e->userMessage()]);
        }

        $warnings = $result->warnings;
        $keys = array_map(fn (ComponentSpec $s) => $s->statutoryKey?->value, $specs);
        foreach (['epf_employee' => 'KWSP', 'socso_employee' => 'PERKESO', 'eis_employee' => 'SIP', 'pcb' => 'PCB'] as $key => $label) {
            if (! in_array($key, $keys, true)) {
                $warnings[] = (string) __('payroll.warnings.structure_missing_statutory', ['scheme' => $label]);
            }
        }

        $structure = DB::transaction(function () use ($structure, $data, $code, $prepared): SalaryStructure {
            $structure ??= new SalaryStructure;
            $isNew = ! $structure->exists;
            $structure->fill([
                'code' => $code,
                'name_ms' => (string) ($data['name_ms'] ?? $code),
                'name_en' => $data['name_en'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'last_validated_at' => now(),
            ]);
            if (! $isNew) {
                $structure->revision = $structure->revision + 1;
            }
            $structure->save();

            $structure->rows()->delete();
            foreach ($prepared as $row) {
                SalaryStructureRow::query()->create(['salary_structure_id' => $structure->id] + $row);
            }

            return $structure;
        });

        return ['structure' => $structure->refresh(), 'dry_run' => $result, 'warnings' => array_values(array_unique($warnings))];
    }

    /** "Salin": duplicate a structure under a new code. */
    public function copy(SalaryStructure $source, string $code, string $name): SalaryStructure
    {
        $rows = $source->rows()->get()->map(fn (SalaryStructureRow $r) => [
            'salary_component_id' => $r->salary_component_id, 'sort_order' => $r->sort_order,
            'calculation_override' => $r->calculation_override?->value, 'amount' => $r->amount, 'formula' => $r->formula,
            'condition' => $r->condition, 'prorate_override' => $r->prorate_override,
        ])->all();

        return $this->save(null, ['code' => $code, 'name_ms' => $name, 'name_en' => $source->name_en, 'rows' => $rows])['structure'];
    }

    /**
     * Static checks; returns the rows to store and the specs for the dry run.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<ComponentSpec>}
     */
    public function validateRows(array $rows): array
    {
        $errors = [];
        $components = SalaryComponent::query()->whereIn('id', array_map(fn ($r) => (int) ($r['salary_component_id'] ?? 0), $rows))->get()->keyBy('id');
        $parser = new Parser;
        $parsed = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $component = $components->get((int) ($row['salary_component_id'] ?? 0));
            if ($component === null || ! $component->is_active) {
                $errors["rows.$i.salary_component_id"] = (string) __('payroll.errors.component_not_allowed');

                continue;
            }
            if (isset($seen[$component->id])) {
                $errors["rows.$i.salary_component_id"] = (string) __('payroll.errors.component_twice', ['code' => $component->code]);

                continue;
            }
            $seen[$component->id] = true;

            $override = CalculationMethod::tryFrom((string) ($row['calculation_override'] ?? ''));
            $method = $override ?? $component->calculation;
            $formula = trim((string) ($row['formula'] ?? '')) !== '' ? trim((string) $row['formula']) : null;
            $condition = trim((string) ($row['condition'] ?? '')) !== '' ? trim((string) $row['condition']) : null;

            if ($method === CalculationMethod::Formula && $formula === null && $component->default_formula === null) {
                $errors["rows.$i.formula"] = (string) __('payroll.formula.formula_method_mismatch', ['code' => $component->code]);
            } elseif ($method !== CalculationMethod::Formula && $formula !== null) {
                $errors["rows.$i.formula"] = (string) __('payroll.formula.formula_method_mismatch', ['code' => $component->code]);
            }

            $formulaAst = null;
            $conditionAst = null;
            try {
                $formulaAst = $formula === null ? null : $parser->parse($formula);
            } catch (FormulaError $e) {
                $errors["rows.$i.formula"] = $e->userMessage();
            }
            try {
                $conditionAst = $condition === null ? null : $parser->parse($condition);
            } catch (FormulaError $e) {
                $errors["rows.$i.condition"] = $e->userMessage();
            }

            $parsed[$i] = [
                'component' => $component,
                'store' => [
                    'salary_component_id' => $component->id,
                    'sort_order' => (int) ($row['sort_order'] ?? ($i + 1)),
                    'calculation_override' => $override?->value,
                    'amount' => isset($row['amount']) && $row['amount'] !== '' ? (string) $row['amount'] : null,
                    'formula' => $formula,
                    'condition' => $condition,
                    'prorate_override' => array_key_exists('prorate_override', $row) && $row['prorate_override'] !== null ? (bool) $row['prorate_override'] : null,
                    'formula_ast' => $formulaAst?->toArray(),
                    'condition_ast' => $conditionAst?->toArray(),
                ],
            ];
        }
        DomainValidation::throwIf($errors);

        // Specs (rows parsed above) for reference checks and the dry run.
        $specs = [];
        $prepared = [];
        foreach ($parsed as $i => $item) {
            $model = new SalaryStructureRow($item['store']);
            $spec = ComponentSpec::fromComponent($item['component'], $model, null, (int) $item['store']['sort_order']);
            $specs[$i] = $spec;
            $prepared[] = $item['store'] + ['referenced_codes' => $spec->references()];
        }

        $codes = array_map(fn (ComponentSpec $s) => $s->code, $specs);
        $prorated = array_map(fn (ComponentSpec $s) => $s->code, array_filter($specs, fn (ComponentSpec $s) => $s->prorate));
        foreach ($specs as $i => $spec) {
            $refs = References::of($spec->formula);
            $condRefs = References::of($spec->condition);
            foreach ([...$refs->all(), ...$condRefs->all()] as $ref) {
                if ($ref === $spec->code) {
                    $errors["rows.$i.formula"] = (string) __('payroll.formula.self_reference', ['code' => $spec->code]);
                } elseif ($ref === 'GROSS' && $spec->kind === ComponentKind::Earning) {
                    $errors["rows.$i.formula"] = (string) __('payroll.formula.gross_in_earning');
                } elseif (! in_array($ref, $codes, true) && ! FunctionRegistry::isVariable($ref)) {
                    $errors["rows.$i.formula"] = (string) __('payroll.formula.unknown_identifier', ['name' => $ref]);
                }
            }
            if ($spec->prorate) {
                foreach ($refs->direct as $ref) {
                    if ($ref !== $spec->code && in_array($ref, $prorated, true)) {
                        $errors["rows.$i.formula"] = (string) __('payroll.formula.double_proration', ['code' => $spec->code, 'ref' => $ref]);
                    }
                }
            }
        }
        DomainValidation::throwIf($errors);

        return [$prepared, array_values($specs)];
    }
}
