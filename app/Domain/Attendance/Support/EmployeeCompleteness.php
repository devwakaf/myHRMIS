<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

/**
 * An employee with unmarked or needs-review days in a range (spec-03 §5.2).
 */
final class EmployeeCompleteness
{
    /**
     * @param  list<string>  $unmarkedDates
     * @param  list<string>  $needsReviewDates
     */
    public function __construct(
        public readonly int $employeeId,
        public readonly string $employeeName,
        public readonly array $unmarkedDates,
        public readonly array $needsReviewDates,
    ) {}
}
