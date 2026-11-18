<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Spec-03 §5.5: assignments / rest days of an employee changed over [from, to ?? +∞]. */
final class ShiftAssignmentsChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $employeeId,
        public readonly CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
    ) {}
}
