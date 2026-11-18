<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\WorkHistoryRecorder;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Organisation\OrganisationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * B2: after the relieving date, set `left`, close the open work-history row,
 * remove the Kakitangan role in this org and deactivate the login only when the
 * user has no role or active employment in any other Organisasi.
 */
final class ProcessSeparation
{
    public function __construct(
        private readonly WorkHistoryRecorder $history,
        private readonly RoleService $roles,
        private readonly OrganisationMembership $membership,
    ) {}

    public function handle(Employee $employee): void
    {
        if ($employee->status === EmployeeStatus::Left || $employee->relieving_date === null) {
            return;
        }

        DB::transaction(function () use ($employee): void {
            $employee->update(['status' => EmployeeStatus::Left]);
            $this->history->close($employee, $employee->relieving_date);

            $user = $employee->user_id === null ? null : User::query()->find($employee->user_id);
            if ($user !== null) {
                $this->roles->remove($user, $employee->organisation_id, PermissionMatrix::KAKITANGAN);

                $otherOrgRoles = array_diff($this->membership->organisationIdsFor($user), [$employee->organisation_id]);
                $otherEmployment = Employee::acrossOrganisations()
                    ->where('user_id', $user->id)
                    ->where('organisation_id', '!=', $employee->organisation_id)
                    ->where('status', '!=', EmployeeStatus::Left->value)
                    ->exists();

                if ($otherOrgRoles === [] && ! $otherEmployment && ! $user->is_super_admin) {
                    $user->update(['is_active' => false]);
                }
            }
        });

        EmployeeSeparated::dispatch($employee->refresh());
    }
}
