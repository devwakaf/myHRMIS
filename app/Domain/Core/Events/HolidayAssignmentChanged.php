<?php

declare(strict_types=1);

namespace App\Domain\Core\Events;

use App\Domain\Core\Models\HolidayListAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An HLA was submitted or cancelled. */
final class HolidayAssignmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly HolidayListAssignment $assignment,
    ) {}
}
