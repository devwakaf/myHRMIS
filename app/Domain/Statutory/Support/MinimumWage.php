<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

/** Perintah Gaji Minimum figures of one set. */
final class MinimumWage
{
    public function __construct(
        public readonly int $rateSetId,
        public readonly string $monthly,
        public readonly string $hourly,
    ) {}
}
