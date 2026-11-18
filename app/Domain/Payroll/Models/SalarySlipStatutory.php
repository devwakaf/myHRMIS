<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Statutory figures of a slip (1:1), with the rate-set ids used and every PCB
 * variable (spec-04 §2.5).
 *
 * @property int $id
 * @property int $salary_slip_id
 * @property string $epf_part
 * @property string $epf_wage
 * @property string $epf_wage_excl_bonus
 * @property string $epf_employee
 * @property string $epf_employer
 * @property string $epf_employee_normal
 * @property string $epf_method
 * @property int|null $epf_rate_set_id
 * @property string $socso_category
 * @property string $socso_wage
 * @property string $socso_employee
 * @property string $socso_employer
 * @property bool $skbbk_applies
 * @property string $skbbk_employee
 * @property int|null $socso_rate_set_id
 * @property bool $eis_eligible
 * @property string $eis_wage
 * @property string $eis_employee
 * @property string $eis_employer
 * @property int|null $eis_rate_set_id
 * @property bool $hrdc_applies
 * @property string $hrdc_wage
 * @property string $hrdc_levy
 * @property string $hrdc_rate
 * @property int|null $hrdc_rate_set_id
 * @property string $pcb_method
 * @property string $pcb_normal
 * @property string $pcb_additional
 * @property string $pcb_before_zakat
 * @property string $zakat_payroll
 * @property string $zakat_external
 * @property string $pcb_net
 * @property string $cp38
 * @property int|null $mtd_rate_set_id
 * @property array<string, mixed>|null $pcb_inputs
 * @property array<string, mixed>|null $ytd_snapshot
 */
class SalarySlipStatutory extends Model
{
    public $timestamps = false;

    protected $table = 'salary_slip_statutory';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        $casts = ['skbbk_applies' => 'boolean', 'eis_eligible' => 'boolean', 'hrdc_applies' => 'boolean',
            'pcb_inputs' => 'array', 'ytd_snapshot' => 'array', 'hrdc_rate' => 'decimal:4'];
        foreach (['epf_wage', 'epf_wage_excl_bonus', 'epf_employee', 'epf_employer', 'epf_employee_normal', 'socso_wage',
            'socso_employee', 'socso_employer', 'skbbk_employee', 'eis_wage', 'eis_employee', 'eis_employer', 'hrdc_wage',
            'hrdc_levy', 'pcb_normal', 'pcb_additional', 'pcb_before_zakat', 'zakat_payroll', 'zakat_external', 'pcb_net', 'cp38'] as $c) {
            $casts[$c] = 'decimal:2';
        }

        return $casts;
    }

    /**
     * @return list<int>
     */
    public function rateSetIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->epf_rate_set_id, $this->socso_rate_set_id, $this->eis_rate_set_id, $this->hrdc_rate_set_id, $this->mtd_rate_set_id,
        ], fn ($id) => $id !== null)));
    }
}
