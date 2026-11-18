<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\CalculationMethod;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\EaBox;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use App\Domain\Payroll\Enums\RoundingMode;
use App\Domain\Payroll\Enums\StatutoryKey;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Komponen Gaji (spec-04 §2.3).
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name_ms
 * @property string $name_en
 * @property ComponentKind $kind
 * @property CalculationMethod $calculation
 * @property StatutoryKey|null $statutory_key
 * @property string|null $default_amount
 * @property string|null $default_formula
 * @property string|null $default_condition
 * @property array<string, mixed>|null $default_formula_ast
 * @property array<string, mixed>|null $default_condition_ast
 * @property bool $prorate
 * @property bool $include_in_gross
 * @property bool $show_on_slip
 * @property bool $show_if_zero
 * @property bool $epf_wage
 * @property bool $socso_wage
 * @property bool $eis_wage
 * @property bool $hrdc_wage
 * @property bool $taxable
 * @property PcbRemunerationType $pcb_type
 * @property string|null $tax_exemption_code
 * @property EaBox $ea_box
 * @property bool $is_epf_bonus
 * @property bool $ot_wage_base
 * @property bool $arrear_eligible
 * @property bool $is_wage_reduction
 * @property int|null $arrear_component_id
 * @property RoundingMode $rounding_mode
 * @property int $sort_order
 * @property bool $is_system
 * @property bool $is_active
 * @property CarbonImmutable|null $first_used_at
 */
class SalaryComponent extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    /** Flags copied onto every slip line (spec-04 §2.5 salary_slip_lines). */
    public const LINE_FLAGS = [
        'include_in_gross', 'epf_wage', 'socso_wage', 'eis_wage', 'hrdc_wage', 'taxable', 'is_epf_bonus', 'ot_wage_base',
        'is_wage_reduction', 'show_on_slip',
    ];

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = [
        'calculation' => 'fixed', 'pcb_type' => 'normal', 'ea_box' => 'none', 'rounding_mode' => 'half_up_2dp',
        'prorate' => false, 'include_in_gross' => true, 'show_on_slip' => true, 'show_if_zero' => false, 'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => ComponentKind::class,
            'calculation' => CalculationMethod::class,
            'statutory_key' => StatutoryKey::class,
            'pcb_type' => PcbRemunerationType::class,
            'ea_box' => EaBox::class,
            'rounding_mode' => RoundingMode::class,
            'default_amount' => 'decimal:2',
            'default_formula_ast' => 'array',
            'default_condition_ast' => 'array',
            'prorate' => 'boolean',
            'include_in_gross' => 'boolean',
            'show_on_slip' => 'boolean',
            'show_if_zero' => 'boolean',
            'epf_wage' => 'boolean',
            'socso_wage' => 'boolean',
            'eis_wage' => 'boolean',
            'hrdc_wage' => 'boolean',
            'taxable' => 'boolean',
            'is_epf_bonus' => 'boolean',
            'ot_wage_base' => 'boolean',
            'arrear_eligible' => 'boolean',
            'is_wage_reduction' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'first_used_at' => 'immutable_datetime',
        ];
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en !== '' ? $this->name_en : $this->name_ms;
    }

    /**
     * @return BelongsTo<SalaryComponent, $this>
     */
    public function arrearComponent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'arrear_component_id');
    }

    public static function byKey(StatutoryKey $key): ?self
    {
        return self::query()->where('statutory_key', $key->value)->first();
    }

    public static function byCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }
}
