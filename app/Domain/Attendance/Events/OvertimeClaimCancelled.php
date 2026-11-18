<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Events;

use App\Domain\Attendance\Models\OvertimeClaim;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Spec-03 §5.5 — dispatched after commit (Payroll warns on draft runs). */
final class OvertimeClaimCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly OvertimeClaim $claim) {}
}
