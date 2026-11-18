<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Models;

use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Approval\Enums\AssigneeOrigin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $approval_request_step_id
 * @property int $user_id
 * @property AssigneeOrigin $origin
 * @property AssigneeDecision $decision
 * @property Carbon|null $decided_at
 * @property string|null $note
 * @property-read User $user
 * @property-read ApprovalRequestStep $step
 */
class ApprovalStepAssignee extends Model
{
    protected $fillable = ['approval_request_step_id', 'user_id', 'origin', 'decision', 'decided_at', 'note'];

    protected function casts(): array
    {
        return [
            'origin' => AssigneeOrigin::class,
            'decision' => AssigneeDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ApprovalRequestStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequestStep::class, 'approval_request_step_id');
    }
}
