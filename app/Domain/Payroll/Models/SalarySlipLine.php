<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\EaBox;
use App\Domain\Payroll\Enums\LineSource;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use Illuminate\Database\Eloquent\Model;

/**
 * One Pendapatan / Potongan / Caruman Majikan / Statistik line with snapshots of
 * the component's names and statutory flags (spec-04 §2.5).
 *
 * @property int $id
 * @property int $salary_slip_id
 * @property ComponentKind $kind
 * @property int $salary_component_id
 * @property string $code
 * @property string $name_ms
 * @property string $name_en
 * @property int $sort_order
 * @property string $amount
 * @property string $full_cycle_amount
 * @property string $structure_amount
 * @property string $additional_amount
 * @property LineSource $source
 * @property int|null $additional_salary_id
 * @property string|null $quantity
 * @property string|null $rate
 * @property string $taxable_exempt_amount
 * @property bool $include_in_gross
 * @property bool $epf_wage
 * @property bool $socso_wage
 * @property bool $eis_wage
 * @property bool $hrdc_wage
 * @property bool $taxable
 * @property PcbRemunerationType $pcb_type
 * @property EaBox $ea_box
 * @property bool $is_epf_bonus
 * @property bool $ot_wage_base
 * @property bool $is_wage_reduction
 * @property bool $show_on_slip
 * @property string|null $note
 */
class SalarySlipLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => ComponentKind::class,
            'source' => LineSource::class,
            'pcb_type' => PcbRemunerationType::class,
            'ea_box' => EaBox::class,
            'amount' => 'decimal:2',
            'full_cycle_amount' => 'decimal:2',
            'structure_amount' => 'decimal:2',
            'additional_amount' => 'decimal:2',
            'quantity' => 'decimal:3',
            'rate' => 'decimal:6',
            'taxable_exempt_amount' => 'decimal:2',
            'include_in_gross' => 'boolean',
            'epf_wage' => 'boolean',
            'socso_wage' => 'boolean',
            'eis_wage' => 'boolean',
            'hrdc_wage' => 'boolean',
            'taxable' => 'boolean',
            'is_epf_bonus' => 'boolean',
            'ot_wage_base' => 'boolean',
            'is_wage_reduction' => 'boolean',
            'show_on_slip' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' && $this->name_en !== '' ? $this->name_en : $this->name_ms;
    }
}
