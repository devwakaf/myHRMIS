<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which slip consumed an Additional Salary for a month (spec-04 §2.6).
 *
 * @property int $id
 * @property int $additional_salary_id
 * @property int $salary_slip_id
 * @property CarbonImmutable $period
 * @property string $amount
 * @property-read SalarySlip $slip
 * @property-read AdditionalSalary $additionalSalary
 */
class AdditionalSalaryApplication extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period' => 'immutable_date', 'amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<SalarySlip, $this>
     */
    public function slip(): BelongsTo
    {
        return $this->belongsTo(SalarySlip::class, 'salary_slip_id');
    }

    /**
     * @return BelongsTo<AdditionalSalary, $this>
     */
    public function additionalSalary(): BelongsTo
    {
        return $this->belongsTo(AdditionalSalary::class);
    }
}
