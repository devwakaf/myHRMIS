<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

/**
 * Result of UnpaidLeaveQuery::unpaidDays (spec-02 §5.2).
 */
final class UnpaidLeaveSummary
{
    /**
     * @param  string  $total  decimal string, 3 dp
     * @param  list<array{date: string, leave_application_id: int, leave_type_id: int, portion: string, unpaid_portion: string}>  $items
     */
    public function __construct(
        public readonly string $total,
        public readonly array $items,
    ) {}
}
