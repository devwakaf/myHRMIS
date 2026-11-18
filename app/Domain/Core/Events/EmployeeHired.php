<?php

declare(strict_types=1);

namespace App\Domain\Core\Events;

use App\Domain\Core\Models\Employee;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A new Kakitangan record was created (hire, rehire or inter-org transfer). */
final class EmployeeHired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Employee $employee,
    ) {}
}
