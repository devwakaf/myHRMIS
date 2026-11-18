<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-assignment amount override of a structure component (replaces the row amount).
 *
 * @property int $id
 * @property int $salary_assignment_id
 * @property int $salary_component_id
 * @property string $amount
 */
class SalaryAssignmentAmount extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<SalaryComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
