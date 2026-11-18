<?php

declare(strict_types=1);

namespace App\Domain\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Holidays added to / removed from a list (B3.7). Leave re-checks pending
 * applications; Payroll warns about locked periods.
 */
final class HolidayListChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $addedDates  Y-m-d
     * @param  list<string>  $removedDates  Y-m-d
     */
    public function __construct(
        public readonly int $holidayListId,
        public readonly array $addedDates,
        public readonly array $removedDates,
    ) {}
}
