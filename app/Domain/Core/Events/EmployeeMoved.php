<?php

declare(strict_types=1);

namespace App\Domain\Core\Events;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeMovement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A movement was applied (or reversed when cancelled). */
final class EmployeeMoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
        public readonly EmployeeMovement $movement,
        public readonly bool $reversed = false,
    ) {}
}
