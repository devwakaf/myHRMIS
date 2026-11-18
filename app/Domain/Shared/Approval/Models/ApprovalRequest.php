<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Enums\RequestStatus;
use App\Domain\Shared\Approval\Enums\StepStatus;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Runtime approval request (A6.5). One per document.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property string $approval_type
 * @property int $approval_workflow_id
 * @property int $applicant_employee_id
 * @property int|null $submitted_by
 * @property RequestStatus $status
 * @property int|null $current_sequence
 * @property array<string, mixed>|null $frozen_attributes
 * @property array<string, mixed>|null $choices
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property string|null $cancel_reason
 * @property-read ApprovalWorkflow $workflow
 * @property-read Employee $applicant
 * @property-read User|null $submitter
 * @property-read Collection<int, ApprovalRequestStep> $steps
 * @property-read (Model&Approvable)|null $approvable
 */
class ApprovalRequest extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'organisation_id', 'approvable_type', 'approvable_id', 'approval_type', 'approval_workflow_id',
        'applicant_employee_id', 'submitted_by', 'status', 'current_sequence', 'frozen_attributes', 'choices',
        'submitted_at', 'completed_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'current_sequence' => 'integer',
            'frozen_attributes' => 'array',
            'choices' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'applicant_employee_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return HasMany<ApprovalRequestStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRequestStep::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('id');
    }

    public function activeStep(): ?ApprovalRequestStep
    {
        return $this->steps()->where('status', StepStatus::Active->value)->first();
    }

    public function isPending(): bool
    {
        return $this->status === RequestStatus::Pending;
    }
}
