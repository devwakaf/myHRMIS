<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pelulus Jabatan (A6.3): ordered list per department per approval type.
 *
 * @property int $id
 * @property int $department_id
 * @property string $approval_type
 * @property int $user_id
 * @property int $sort_order
 * @property-read User $user
 */
class DepartmentApprover extends Model
{
    use LogsBusinessActivity;

    protected $fillable = ['department_id', 'approval_type', 'user_id', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
