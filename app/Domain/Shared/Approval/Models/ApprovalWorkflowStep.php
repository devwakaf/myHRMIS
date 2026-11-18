<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Models;

use App\Domain\Shared\Approval\Enums\OnNoApprover;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Enums\StepMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One configured step of a workflow version (A6.4). Never edited once the
 * version has been used (a new version is published instead).
 *
 * @property int $id
 * @property int $approval_workflow_id
 * @property int $sequence
 * @property string $name
 * @property RuleType $rule_type
 * @property array<string, mixed>|null $rule_config
 * @property StepMode $mode
 * @property bool $skip_if_applicant
 * @property bool $skip_if_same_as_previous
 * @property bool $exclude_submitter
 * @property list<array{attribute: string, operator: string, value: mixed}>|null $condition
 * @property OnNoApprover $on_no_approver
 * @property int|null $remind_after_days
 * @property-read ApprovalWorkflow $workflow
 */
class ApprovalWorkflowStep extends Model
{
    protected $fillable = [
        'approval_workflow_id', 'sequence', 'name', 'rule_type', 'rule_config', 'mode', 'skip_if_applicant',
        'skip_if_same_as_previous', 'exclude_submitter', 'condition', 'on_no_approver', 'remind_after_days',
    ];

    protected $attributes = [
        'mode' => 'any_one',
        'skip_if_applicant' => true,
        'skip_if_same_as_previous' => true,
        'exclude_submitter' => true,
        'on_no_approver' => 'fail',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'rule_type' => RuleType::class,
            'rule_config' => 'array',
            'mode' => StepMode::class,
            'skip_if_applicant' => 'boolean',
            'skip_if_same_as_previous' => 'boolean',
            'exclude_submitter' => 'boolean',
            'condition' => 'array',
            'on_no_approver' => OnNoApprover::class,
            'remind_after_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'approval_workflow_id')->withoutGlobalScopes();
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return ($this->rule_config ?? [])[$key] ?? $default;
    }
}
