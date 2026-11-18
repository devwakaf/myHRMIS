<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeApprover;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;

/**
 * Tab "Pelulus": one named approver per approval type (A6.3). Being named grants
 * the derived `pelulus` role (A6.8).
 */
final class SaveEmployeeApprovers
{
    public function __construct(
        private readonly ApprovalTypeRegistry $registry,
        private readonly OrganisationMembership $membership,
        private readonly RoleService $roles,
    ) {}

    /**
     * @param  array<string, int|string|null>  $approvers  approval_type => user_id
     */
    public function handle(Employee $employee, array $approvers): void
    {
        $members = $this->membership->memberUserIds($employee->organisation_id);
        $errors = [];

        foreach ($approvers as $type => $userId) {
            if (! $this->registry->has((string) $type)) {
                $errors["approvers.$type"] = __('approval.errors.unknown_type', ['type' => $type]);

                continue;
            }
            if ($userId === null || $userId === '') {
                continue;
            }
            if (! in_array((int) $userId, $members, true)) {
                $errors["approvers.$type"] = __('approval.errors.invalid_user');
            }
            if ($employee->user_id !== null && (int) $userId === $employee->user_id) {
                $errors["approvers.$type"] = __('approval.errors.self_approver');
            }
        }
        DomainValidation::throwIf($errors);

        foreach ($approvers as $type => $userId) {
            if ($userId === null || $userId === '') {
                EmployeeApprover::query()->where('employee_id', $employee->id)->where('approval_type', $type)->delete();

                continue;
            }

            EmployeeApprover::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'approval_type' => $type],
                ['user_id' => (int) $userId],
            );

            $user = User::query()->find((int) $userId);
            if ($user !== null) {
                $this->roles->assign($user, $employee->organisation_id, PermissionMatrix::PELULUS);
            }
        }
    }
}
