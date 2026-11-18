<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use Carbon\CarbonImmutable;

/**
 * Attendance evidence for a worked rest day / holiday (spec-02 §3.16).
 * `verified = false` means no Attendance module confirmed it (M2 Null adapter):
 * the request is flagged and the approver confirms manually.
 */
final class WorkedDay
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $status,
        public readonly bool $verified = true,
        public readonly bool $halfDay = false,
    ) {}

    public static function unverified(CarbonImmutable $date): self
    {
        return new self($date, 'unverified', false);
    }
}
