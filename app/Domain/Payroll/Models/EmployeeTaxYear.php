<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Materialised YTD per employee per year (spec-04 §2.9). Never updated
 * incrementally: RefreshTaxYear rebuilds the whole row from submitted slips,
 * submitted declarations and assignment opening columns.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property int $tax_year
 * @property CarbonImmutable|null $through_period
 * @property string $y_normal
 * @property string $y_additional
 * @property string $k
 * @property string $x
 * @property string $z
 * @property string $lp
 * @property string $cp38_total
 * @property string $socso_employee
 * @property string $eis_employee
 * @property string $skbbk_employee
 * @property string $epf_employer
 * @property array<string, string>|null $ea_boxes
 * @property array<string, string>|null $tax_exempt_used
 * @property CarbonImmutable|null $rebuilt_at
 */
class EmployeeTaxYear extends Model
{
    use BelongsToOrganisation;

    public const AMOUNTS = ['y_normal', 'y_additional', 'k', 'x', 'z', 'lp', 'cp38_total', 'socso_employee', 'eis_employee', 'skbbk_employee', 'epf_employer'];

    protected $guarded = ['id', 'organisation_id'];

    protected function casts(): array
    {
        $casts = [
            'tax_year' => 'integer',
            'through_period' => 'immutable_date',
            'ea_boxes' => 'array',
            'tax_exempt_used' => 'array',
            'rebuilt_at' => 'immutable_datetime',
        ];
        foreach (self::AMOUNTS as $column) {
            $casts[$column] = 'decimal:2';
        }

        return $casts;
    }
}
