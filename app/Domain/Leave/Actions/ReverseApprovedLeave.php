<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Services\LeaveLedger;

/**
 * Cancelling an approved application (spec-02 §3.13): one reversal row per
 * application debit (never deletes), then Attendance unmarks the days.
 * Called from LeaveApplication::onApprovalCancelled(wasApproved: true).
 */
final class ReverseApprovedLeave
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeaveAttendancePort $attendance,
    ) {}

    public function handle(LeaveApplication $application, bool $unmarkAttendance = true): int
    {
        $rows = $this->ledger->reverseAll(
            LeaveMovement::query()->fromSource($application)->where('kind', MovementKind::Application->value),
            LeaveLedger::today(),
            $application,
        );

        if ($unmarkAttendance) {
            $this->attendance->unmarkLeave($application);
        }

        return $rows->count();
    }
}
