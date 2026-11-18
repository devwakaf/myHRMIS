<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Actions;

use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Enums\OnNoApprover;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Enums\StepMode;
use App\Domain\Shared\Approval\Models\ApprovalWorkflow;

/**
 * A6.9: every registered type gets one workflow "Kelulusan Standard" with a single
 * step (named or department approver, applicant chooses, any one, fail if none).
 */
final class SeedDefaultWorkflows
{
    public function __construct(
        private readonly ApprovalTypeRegistry $registry,
        private readonly PublishApprovalWorkflow $publish,
    ) {}

    public function handle(int $organisationId): void
    {
        foreach ($this->registry->keys() as $type) {
            $exists = ApprovalWorkflow::acrossOrganisations()
                ->where('organisation_id', $organisationId)
                ->where('approval_type', $type)
                ->exists();

            if ($exists) {
                continue;
            }

            $this->publish->handle($organisationId, $type, [
                'name' => (string) __('approval.defaults.workflow_name', [], 'ms'),
                'prevent_self_approval' => true,
                'allow_override' => true,
                'notify_applicant_each_step' => false,
                'steps' => [[
                    'name' => (string) __('approval.defaults.step_name', [], 'ms'),
                    'rule_type' => RuleType::NamedOrDepartment->value,
                    'rule_config' => ['selection' => 'applicant_chooses', 'walk_ancestors' => true],
                    'mode' => StepMode::AnyOne->value,
                    'skip_if_applicant' => true,
                    'skip_if_same_as_previous' => true,
                    'exclude_submitter' => true,
                    'on_no_approver' => OnNoApprover::Fail->value,
                ]],
            ]);
        }
    }
}
