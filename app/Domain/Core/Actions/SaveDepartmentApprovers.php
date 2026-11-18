<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\DepartmentApprover;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Pelulus Jabatan" per approval type, ordered (A6.3).
 */
final class SaveDepartmentApprovers
{
    public function __construct(
        private readonly ApprovalTypeRegistry $registry,
        private readonly OrganisationMembership $membership,
        private readonly RoleService $roles,
    ) {}

    /**
     * @param  array<string, list<int|string>>  $approvers  approval_type => ordered user ids
     */
    public function handle(Department $department, array $approvers): void
    {
        $members = $this->membership->memberUserIds($department->organisation_id);
        $errors = [];

        foreach ($approvers as $type => $userIds) {
            if (! $this->registry->has((string) $type)) {
                $errors["approvers.$type"] = __('approval.errors.unknown_type', ['type' => $type]);

                continue;
            }
            foreach ($userIds as $id) {
                if (! in_array((int) $id, $members, true)) {
                    $errors["approvers.$type"] = __('approval.errors.invalid_user');
                }
            }
        }
        DomainValidation::throwIf($errors);

        DB::transaction(function () use ($department, $approvers): void {
            foreach ($approvers as $type => $userIds) {
                DepartmentApprover::query()->where('department_id', $department->id)->where('approval_type', $type)->delete();
                foreach (array_values(array_unique(array_map('intval', $userIds))) as $i => $userId) {
                    DepartmentApprover::query()->create([
                        'department_id' => $department->id,
                        'approval_type' => $type,
                        'user_id' => $userId,
                        'sort_order' => $i,
                    ]);
                    $user = User::query()->find($userId);
                    if ($user !== null) {
                        $this->roles->assign($user, $department->organisation_id, PermissionMatrix::PELULUS);
                    }
                }
            }
        });
    }
}
