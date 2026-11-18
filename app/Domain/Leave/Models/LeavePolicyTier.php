<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tier Perkhidmatan (spec-02 §2.3).
 *
 * @property int $id
 * @property int $leave_policy_row_id
 * @property int $min_service_months
 * @property string $days
 */
class LeavePolicyTier extends Model
{
    protected $fillable = ['leave_policy_row_id', 'min_service_months', 'days'];

    protected function casts(): array
    {
        return ['days' => 'decimal:3', 'min_service_months' => 'integer'];
    }
}
