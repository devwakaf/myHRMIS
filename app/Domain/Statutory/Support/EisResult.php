<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

/** SIP outcome. */
final class EisResult
{
    /**
     * @param  array{from: string, to: string}|null  $band
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly string $wage,
        public readonly string $employer,
        public readonly string $employee,
        public readonly ?int $rateSetId,
        public readonly ?array $band,
    ) {}
}
