<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Services;

use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DepartmentApprover;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeApprover;
use App\Domain\Shared\Approval\Enums\AssigneeOrigin;
use App\Domain\Shared\Approval\Enums\RuleType;
use App\Domain\Shared\Approval\Models\ApprovalWorkflowStep;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Shared\Support\DomainValidation;

/**
 * Turns a step's rule into users (A6.4). Every rule returns only active users
 * who are members of the Organisasi, de-duplicated, in rule order.
 */
final class ApproverResolver
{
    /** @var array<int, list<int>> */
    private array $memberCache = [];

    public function __construct(private readonly OrganisationMembership $membership) {}

    /**
     * @param  array<string, mixed>  $choices  applicant picks keyed by workflow step id
     * @param  bool  $strictChoice  validate the applicant's pick (submit) instead of tolerating it (preview)
     */
    public function resolve(
        ApprovalWorkflowStep $step,
        Employee $applicant,
        string $approvalType,
        array $choices = [],
        bool $strictChoice = true,
    ): ResolvedApprovers {
        $orgId = (int) $applicant->organisation_id;
        $this->memberCache = []; // membership may change between calls; memo per resolution only

        return match ($step->rule_type) {
            RuleType::NamedApprover => new ResolvedApprovers($this->filter($orgId, $this->named($applicant, $approvalType))),
            RuleType::DepartmentApprovers => $this->departmentRule($step, $applicant, $approvalType, $choices, $strictChoice, false),
            RuleType::NamedOrDepartment => $this->departmentRule($step, $applicant, $approvalType, $choices, $strictChoice, true),
            RuleType::DepartmentHead => new ResolvedApprovers($this->filter($orgId, $this->departmentHead($step, $applicant))),
            RuleType::ReportsTo => new ResolvedApprovers($this->filter($orgId, $this->reportsTo($applicant, (int) $step->config('level', 1)))),
            RuleType::Role => new ResolvedApprovers($this->membership->usersWithRole($orgId, (string) $step->config('role', ''))),
            RuleType::SpecificUser => new ResolvedApprovers($this->filter($orgId, array_filter([(int) $step->config('user_id', 0)]))),
        };
    }

    /**
     * @return list<int>
     */
    private function named(Employee $applicant, string $approvalType): array
    {
        $userId = EmployeeApprover::query()
            ->where('employee_id', $applicant->id)
            ->where('approval_type', $approvalType)
            ->value('user_id');

        return $userId === null ? [] : [(int) $userId];
    }

    /**
     * @return list<int>
     */
    private function departmentApproversOf(int $departmentId, string $approvalType): array
    {
        return DepartmentApprover::query()
            ->where('department_id', $departmentId)
            ->where('approval_type', $approvalType)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $choices
     */
    private function departmentRule(
        ApprovalWorkflowStep $step,
        Employee $applicant,
        string $approvalType,
        array $choices,
        bool $strictChoice,
        bool $namedFirst,
    ): ResolvedApprovers {
        $orgId = (int) $applicant->organisation_id;
        $walkAncestors = (bool) $step->config('walk_ancestors', true);
        $selection = (string) $step->config('selection', 'first');
        $department = Department::query()->find($applicant->department_id);

        $named = $namedFirst ? $this->filter($orgId, $this->named($applicant, $approvalType)) : [];
        $own = $department === null ? [] : $this->filter($orgId, $this->departmentApproversOf($department->id, $approvalType));

        if ($selection === 'applicant_chooses') {
            $candidates = array_merge($named, $own);
            if ($department !== null) {
                foreach ($department->ancestorsNearestFirst() as $ancestor) {
                    $candidates = array_merge($candidates, $this->filter($orgId, $this->departmentApproversOf($ancestor->id, $approvalType)));
                }
            }
            $candidates = array_values(array_unique($candidates));
            $default = $named[0] ?? $own[0] ?? null;

            $picked = $choices[(string) $step->id] ?? null;
            if ($picked !== null && $picked !== '') {
                $picked = (int) $picked;
                if (! in_array($picked, $candidates, true)) {
                    if ($strictChoice) {
                        throw DomainValidation::withMessages([
                            'choices.'.$step->id => __('approval.errors.choice_not_allowed', ['step' => $step->name]),
                        ]);
                    }
                    $picked = null;
                }
            }

            $chosen = $picked ?? $default;
            if ($chosen === null) {
                return new ResolvedApprovers([], AssigneeOrigin::ApplicantChoice, $candidates, null, $candidates !== []);
            }

            return new ResolvedApprovers([$chosen], $picked !== null ? AssigneeOrigin::ApplicantChoice : AssigneeOrigin::Rule, $candidates, $default);
        }

        if ($named !== []) {
            return new ResolvedApprovers($named);
        }

        $found = $own;
        if ($found === [] && $walkAncestors && $department !== null) {
            foreach ($department->ancestorsNearestFirst() as $ancestor) {
                $found = $this->filter($orgId, $this->departmentApproversOf($ancestor->id, $approvalType));
                if ($found !== []) {
                    break;
                }
            }
        }

        if ($found !== [] && $selection !== 'all') {
            $found = [$found[0]];
        }

        return new ResolvedApprovers($found);
    }

    /**
     * Head of the applicant's department; with walk_up, climb while the post is
     * vacant, has no user, or is the applicant.
     *
     * @return list<int>
     */
    private function departmentHead(ApprovalWorkflowStep $step, Employee $applicant): array
    {
        $walkUp = (bool) $step->config('walk_up', true);
        $department = Department::query()->find($applicant->department_id);

        while ($department !== null) {
            $head = $department->head_employee_id === null ? null : Employee::query()->find($department->head_employee_id);
            $usable = $head !== null && $head->user_id !== null && ! $head->isLeft();

            if ($usable && ($head->id !== $applicant->id || ! $walkUp)) {
                return [(int) $head->user_id];
            }

            if (! $walkUp) {
                return [];
            }

            $department = $department->parent_id === null ? null : Department::query()->find($department->parent_id);
        }

        return [];
    }

    /**
     * @return list<int>
     */
    private function reportsTo(Employee $applicant, int $level): array
    {
        $level = max(1, min(3, $level));
        $current = $applicant;
        $seen = [$applicant->id => true];

        for ($i = 0; $i < $level; $i++) {
            if ($current->reports_to_id === null) {
                return [];
            }
            $current = Employee::query()->find($current->reports_to_id);
            if ($current === null || isset($seen[$current->id])) {
                return [];
            }
            $seen[$current->id] = true;
        }

        return ($current->user_id === null || $current->isLeft()) ? [] : [(int) $current->user_id];
    }

    /**
     * Keep only active organisation members, de-duplicated, order preserved.
     *
     * @param  array<int, int>  $userIds
     * @return list<int>
     */
    private function filter(int $organisationId, array $userIds): array
    {
        $members = $this->memberCache[$organisationId] ??= $this->membership->memberUserIds($organisationId);

        return array_values(array_unique(array_filter($userIds, fn (int $id) => in_array($id, $members, true))));
    }

    public function flushCache(): void
    {
        $this->memberCache = [];
    }
}
