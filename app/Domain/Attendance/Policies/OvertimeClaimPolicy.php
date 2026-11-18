<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Models\User;

/**
 * Tuntutan OT access (spec-03 §8): HR prepares and submits; the employee sees
 * their own claim and its progress; step assignees see the decision context.
 */
final class OvertimeClaimPolicy extends ApprovableDocumentPolicy
{
    protected function viewAllAbility(): string
    {
        return 'attendance.overtime.view-all';
    }

    protected function createAbility(): string
    {
        return 'attendance.overtime.manage';
    }

    public function manage(User $user, OvertimeClaim $claim): bool
    {
        return $claim->status === ApprovalStatus::Draft && $user->can('attendance.overtime.manage');
    }

    public function submit(User $user, OvertimeClaim $claim): bool
    {
        return $claim->status === ApprovalStatus::Draft && $user->can('attendance.overtime.submit-for-approval');
    }

    public function cancel(User $user, OvertimeClaim $claim): bool
    {
        return $user->can('attendance.overtime.cancel');
    }
}
