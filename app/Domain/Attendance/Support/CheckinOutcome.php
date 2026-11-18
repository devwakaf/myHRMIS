<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\EmployeeCheckin;

/**
 * Result of a CheckinIngestor: the stored punch, and whether it already existed
 * (idempotency key replay or debounce — "sudah direkodkan").
 */
final class CheckinOutcome
{
    public function __construct(
        public readonly EmployeeCheckin $checkin,
        public readonly bool $duplicate = false,
    ) {}
}
