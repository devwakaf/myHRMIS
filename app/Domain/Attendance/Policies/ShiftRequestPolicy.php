<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Models\ShiftRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Permohonan Shift access (spec-03 §8). */
final class ShiftRequestPolicy extends ApprovableDocumentPolicy
{
    protected function viewAllAbility(): string
    {
        return 'attendance.view-all';
    }

    protected function createAbility(): string
    {
        return 'attendance.shift-request.create';
    }

    public function cancelApproved(User $user, ShiftRequest $request): bool
    {
        if ($user->can('attendance.manage')) {
            return true;
        }

        return $this->isApplicant($user, $request) && $request->from_date->greaterThan(CarbonImmutable::today());
    }
}
