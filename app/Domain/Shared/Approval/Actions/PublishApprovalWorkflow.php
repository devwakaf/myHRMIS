<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Actions;

use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Enums\OnNoApprover;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Enums\StepMode;
use App\Domain\Shared\Approval\Models\ApprovalWorkflow;
use App\Domain\Shared\Approval\Services\ConditionEvaluator;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Save ("Terbitkan") the workflow of a request type (A6.4). A version that has
 * been used is never edited: a new version is created and the old one deactivated.
 */
final class PublishApprovalWorkflow
{
    public function __construct(
        private readonly ApprovalTypeRegistry $registry,
        private readonly ConditionEvaluator $conditions,
        private readonly OrganisationMembership $membership,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     prevent_self_approval?: bool,
     *     allow_override?: bool,
     *     notify_applicant_each_step?: bool,
     *     steps: list<array<string, mixed>>,
     * }  $data
     */
    public function handle(int $organisationId, string $approvalType, array $data, ?User $actor = null): ApprovalWorkflow
    {
        $this->registry->get($approvalType);
        $steps = $this->validate($organisationId, $approvalType, $data);

        return DB::transaction(function () use ($organisationId, $approvalType, $data, $steps, $actor): ApprovalWorkflow {
            $current = ApprovalWorkflow::acrossOrganisations()
                ->where('organisation_id', $organisationId)
                ->where('approval_type', $approvalType)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'prevent_self_approval' => (bool) ($data['prevent_self_approval'] ?? true),
                'allow_override' => (bool) ($data['allow_override'] ?? true),
                'notify_applicant_each_step' => (bool) ($data['notify_applicant_each_step'] ?? false),
                'published_at' => now(),
                'published_by' => $actor?->id,
            ];

            if ($current !== null && ! $current->isUsed()) {
                $current->update($attributes);
                $current->steps()->delete();
                $workflow = $current;
            } else {
                $nextVersion = (int) ApprovalWorkflow::acrossOrganisations()
                    ->where('organisation_id', $organisationId)
                    ->where('approval_type', $approvalType)
                    ->max('version') + 1;

                ApprovalWorkflow::acrossOrganisations()
                    ->where('organisation_id', $organisationId)
                    ->where('approval_type', $approvalType)
                    ->update(['is_active' => false]);

                $workflow = ApprovalWorkflow::query()->create(array_merge($attributes, [
                    'organisation_id' => $organisationId,
                    'approval_type' => $approvalType,
                    'version' => $nextVersion,
                    'is_active' => true,
                ]));
            }

            foreach ($steps as $i => $step) {
                $workflow->steps()->create(array_merge($step, ['sequence' => $i + 1]));
            }

            return $workflow->load('steps');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function validate(int $organisationId, string $approvalType, array $data): array
    {
        $errors = [];
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = __('validation.required', ['attribute' => __('approval.fields.workflow_name')]);
        }

        $steps = array_values($data['steps'] ?? []);
        if ($steps === [] || count($steps) > 10) {
            $errors['steps'] = __('approval.errors.step_count');
        }

        $members = null;
        $clean = [];
        foreach ($steps as $i => $step) {
            $prefix = "steps.$i";
            $rule = RuleType::tryFrom((string) ($step['rule_type'] ?? ''));
            $mode = StepMode::tryFrom((string) ($step['mode'] ?? 'any_one'));
            $onNo = OnNoApprover::tryFrom((string) ($step['on_no_approver'] ?? 'fail'));
            $config = is_array($step['rule_config'] ?? null) ? $step['rule_config'] : [];

            if (trim((string) ($step['name'] ?? '')) === '') {
                $errors["$prefix.name"] = __('validation.required', ['attribute' => __('approval.fields.step_name')]);
            }
            if ($rule === null) {
                $errors["$prefix.rule_type"] = __('approval.errors.invalid_rule');

                continue;
            }
            if ($mode === null) {
                $errors["$prefix.mode"] = __('approval.errors.invalid_mode');
            }
            if ($onNo === null) {
                $errors["$prefix.on_no_approver"] = __('approval.errors.invalid_on_no_approver');
            }

            $config = $this->normaliseConfig($rule, $config);
            if ($rule === RuleType::Role && ! in_array($config['role'] ?? null, PermissionMatrix::roles(), true)) {
                $errors["$prefix.rule_config.role"] = __('approval.errors.invalid_role');
            }
            if ($rule === RuleType::SpecificUser) {
                $members ??= $this->membership->memberUserIds($organisationId);
                if (! in_array((int) ($config['user_id'] ?? 0), $members, true)) {
                    $errors["$prefix.rule_config.user_id"] = __('approval.errors.invalid_user');
                }
            }

            $condition = $step['condition'] ?? null;
            $condition = is_array($condition) && $condition !== [] ? array_values($condition) : null;
            foreach ($this->conditions->validate($approvalType, $condition) as $message) {
                $errors["$prefix.condition"] = $message;
            }

            $remind = $step['remind_after_days'] ?? null;
            if ($remind !== null && $remind !== '' && (! is_numeric($remind) || (int) $remind < 1 || (int) $remind > 60)) {
                $errors["$prefix.remind_after_days"] = __('approval.errors.invalid_remind_days');
            }

            $clean[] = [
                'name' => trim((string) ($step['name'] ?? '')),
                'rule_type' => $rule,
                'rule_config' => $config === [] ? null : $config,
                'mode' => $mode ?? StepMode::AnyOne,
                'skip_if_applicant' => (bool) ($step['skip_if_applicant'] ?? true),
                'skip_if_same_as_previous' => (bool) ($step['skip_if_same_as_previous'] ?? true),
                'exclude_submitter' => (bool) ($step['exclude_submitter'] ?? true),
                'condition' => $condition,
                'on_no_approver' => $onNo ?? OnNoApprover::Fail,
                'remind_after_days' => ($remind === null || $remind === '') ? null : (int) $remind,
            ];
        }

        DomainValidation::throwIf($errors);

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normaliseConfig(RuleType $rule, array $config): array
    {
        return match ($rule) {
            RuleType::DepartmentApprovers, RuleType::NamedOrDepartment => [
                'walk_ancestors' => (bool) ($config['walk_ancestors'] ?? true),
                'selection' => in_array($config['selection'] ?? 'first', ['first', 'all', 'applicant_chooses'], true)
                    ? ($config['selection'] ?? 'first') : 'first',
            ],
            RuleType::DepartmentHead => ['walk_up' => (bool) ($config['walk_up'] ?? true)],
            RuleType::ReportsTo => ['level' => max(1, min(3, (int) ($config['level'] ?? 1)))],
            RuleType::Role => ['role' => (string) ($config['role'] ?? '')],
            RuleType::SpecificUser => ['user_id' => (int) ($config['user_id'] ?? 0)],
            RuleType::NamedApprover => [],
        };
    }
}
