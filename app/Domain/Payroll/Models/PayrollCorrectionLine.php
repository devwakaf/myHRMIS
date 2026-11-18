<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $payroll_correction_id
 * @property int $salary_component_id
 * @property string $amount
 * @property string|null $basis
 */
class PayrollCorrectionLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
