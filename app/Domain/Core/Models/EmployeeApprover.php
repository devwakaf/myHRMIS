<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pelulus Kakitangan (A6.3): one named approver per employee per approval type.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $approval_type
 * @property int $user_id
 * @property-read User $user
 */
class EmployeeApprover extends Model
{
    use LogsBusinessActivity;

    protected $fillable = ['employee_id', 'approval_type', 'user_id'];

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
