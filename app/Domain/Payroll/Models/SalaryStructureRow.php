<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\CalculationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a Struktur Gaji (spec-04 §2.4). ASTs are parsed at save time.
 *
 * @property int $id
 * @property int $salary_structure_id
 * @property int $salary_component_id
 * @property int $sort_order
 * @property CalculationMethod|null $calculation_override
 * @property string|null $amount
 * @property string|null $formula
 * @property string|null $condition
 * @property bool|null $prorate_override
 * @property array<string, mixed>|null $formula_ast
 * @property array<string, mixed>|null $condition_ast
 * @property list<string>|null $referenced_codes
 * @property-read SalaryComponent $component
 */
class SalaryStructureRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'calculation_override' => CalculationMethod::class,
            'amount' => 'decimal:2',
            'prorate_override' => 'boolean',
            'formula_ast' => 'array',
            'condition_ast' => 'array',
            'referenced_codes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SalaryComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /**
     * @return BelongsTo<SalaryStructure, $this>
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }
}
