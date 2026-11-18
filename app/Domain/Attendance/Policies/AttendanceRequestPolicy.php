<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Models\AttendanceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Permohonan Kehadiran access (spec-03 §8). */
final class AttendanceRequestPolicy extends ApprovableDocumentPolicy
{
    protected function viewAllAbility(): string
    {
        return 'attendance.view-all';
    }

    protected function createAbility(): string
    {
        return 'attendance.request.create';
    }

    /** HR any time (outside locked periods, checked by the action); applicant only while all dates are future. */
    public function cancelApproved(User $user, AttendanceRequest $request): bool
    {
        if ($user->can('attendance.request.create-for-others')) {
            return true;
        }

        return $this->isApplicant($user, $request) && $request->from_date->greaterThan(CarbonImmutable::today());
    }
}
