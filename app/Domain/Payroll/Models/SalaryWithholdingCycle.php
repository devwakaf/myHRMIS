<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One withheld month; released individually ("Lepaskan").
 *
 * @property int $id
 * @property int $salary_withholding_id
 * @property CarbonImmutable $period
 * @property int|null $salary_slip_id
 * @property CarbonImmutable|null $released_at
 * @property int|null $released_by
 * @property string|null $release_reason
 * @property int|null $payroll_file_id
 * @property-read SalaryWithholding $withholding
 */
class SalaryWithholdingCycle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period' => 'immutable_date', 'released_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<SalaryWithholding, $this>
     */
    public function withholding(): BelongsTo
    {
        return $this->belongsTo(SalaryWithholding::class, 'salary_withholding_id');
    }
}
