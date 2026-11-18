<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Events;

use App\Domain\Attendance\Models\Attendance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Spec-03 §5.5 — dispatched after commit; never used for mandatory effects. */
final class AttendanceMarked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Attendance $attendance) {}
}
