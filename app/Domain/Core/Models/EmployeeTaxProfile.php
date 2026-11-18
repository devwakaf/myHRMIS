<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Enums\MtdScheme;
use App\Domain\Core\Enums\TaxResidency;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use Database\Factories\Core\EmployeeTaxProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tax profile (B1.7, 1:1 with employee) — snapshotted by Payroll in M4.
 *
 * @property int $id
 * @property int $employee_id
 * @property string|null $tin
 * @property TaxResidency $tax_residency
 * @property MtdScheme $mtd_scheme
 * @property int $pcb_category
 * @property string|null $pcb_category_override_reason
 * @property bool|null $spouse_working
 * @property bool $spouse_disabled
 * @property int $children_basic
 * @property int $children_higher_ed
 * @property int $children_disabled
 * @property int $children_disabled_higher_ed
 * @property bool $claims_child_relief
 * @property bool $zakat_via_payroll
 * @property Money|null $zakat_monthly_amount
 */
class EmployeeTaxProfile extends Model
{
    /** @use HasFactory<EmployeeTaxProfileFactory> */
    use HasFactory, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = [
        'tax_residency' => 'resident',
        'mtd_scheme' => 'normal',
        'pcb_category' => 1,
        'spouse_disabled' => false,
        'children_basic' => 0,
        'children_higher_ed' => 0,
        'children_disabled' => 0,
        'children_disabled_higher_ed' => 0,
        'claims_child_relief' => true,
        'zakat_via_payroll' => false,
    ];

    protected function casts(): array
    {
        return [
            'tax_residency' => TaxResidency::class,
            'mtd_scheme' => MtdScheme::class,
            'pcb_category' => 'integer',
            'spouse_working' => 'boolean',
            'spouse_disabled' => 'boolean',
            'children_basic' => 'integer',
            'children_higher_ed' => 'integer',
            'children_disabled' => 'integer',
            'children_disabled_higher_ed' => 'integer',
            'claims_child_relief' => 'boolean',
            'zakat_via_payroll' => 'boolean',
            'zakat_monthly_amount' => MoneyCast::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['tin', 'zakat_monthly_amount', 'zakat_ref_no'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
