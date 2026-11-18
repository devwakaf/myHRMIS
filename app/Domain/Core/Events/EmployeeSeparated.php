<?php

declare(strict_types=1);

namespace App\Domain\Core\Events;

use App\Domain\Core\Models\Employee;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Separation processed (status left, login revoked where applicable). */
final class EmployeeSeparated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
    ) {}
}
