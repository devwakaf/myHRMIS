<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per month × component arrear computation (sepatutnya dibayar / telah dibayar / beza).
 *
 * @property int $id
 * @property int $arrear_id
 * @property CarbonImmutable $period
 * @property int $salary_component_id
 * @property string $entitled_amount
 * @property string $paid_amount
 * @property string $difference
 * @property int|null $old_salary_slip_id
 * @property bool $included
 * @property string|null $basis
 */
class ArrearLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => 'immutable_date',
            'entitled_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'included' => 'boolean',
        ];
    }
}
