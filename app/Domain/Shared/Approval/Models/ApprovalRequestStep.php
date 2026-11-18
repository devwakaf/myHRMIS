<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Models;

use App\Domain\Shared\Approval\Enums\SkipReason;
use App\Domain\Shared\Approval\Enums\StepMode;
use App\Domain\Shared\Approval\Enums\StepStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Step progress of one request (A6.5).
 *
 * @property int $id
 * @property int $approval_request_id
 * @property int $approval_workflow_step_id
 * @property int $sequence
 * @property string $name
 * @property StepMode $mode
 * @property StepStatus $status
 * @property SkipReason|null $skip_reason
 * @property Carbon|null $activated_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_reminded_at
 * @property Carbon|null $no_approver_alerted_at
 * @property-read ApprovalRequest $request
 * @property-read ApprovalWorkflowStep $workflowStep
 * @property-read Collection<int, ApprovalStepAssignee> $assignees
 */
class ApprovalRequestStep extends Model
{
    protected $fillable = [
        'approval_request_id', 'approval_workflow_step_id', 'sequence', 'name', 'mode', 'status', 'skip_reason',
        'activated_at', 'completed_at', 'last_reminded_at', 'no_approver_alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'mode' => StepMode::class,
            'status' => StepStatus::class,
            'skip_reason' => SkipReason::class,
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'no_approver_alerted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<ApprovalWorkflowStep, $this>
     */
    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflowStep::class, 'approval_workflow_step_id');
    }

    /**
     * @return HasMany<ApprovalStepAssignee, $this>
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(ApprovalStepAssignee::class);
    }
}
