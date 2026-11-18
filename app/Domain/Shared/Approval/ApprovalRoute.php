<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval;

/**
 * "Laluan Kelulusan" preview (A6.6 step 4): the dry run shown on module forms.
 */
final class ApprovalRoute
{
    /**
     * @param  list<array{
     *     workflow_step_id: int,
     *     sequence: int,
     *     name: string,
     *     mode: string,
     *     outcome: string,
     *     skip_reason: string|null,
     *     approvers: list<array{id: int, name: string}>,
     *     candidates: list<array{id: int, name: string}>,
     *     default_choice: int|null,
     * }>  $steps
     */
    public function __construct(
        public readonly int $workflowId,
        public readonly int $workflowVersion,
        public readonly string $workflowName,
        public readonly array $steps,
    ) {}

    /** True when submit would fail because a `fail` step finds nobody. */
    public function hasBlockingStep(): bool
    {
        foreach ($this->steps as $step) {
            if ($step['outcome'] === 'no_approver_fail' || $step['outcome'] === 'choice_required') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workflow_id' => $this->workflowId,
            'version' => $this->workflowVersion,
            'name' => $this->workflowName,
            'steps' => $this->steps,
            'blocked' => $this->hasBlockingStep(),
        ];
    }
}
