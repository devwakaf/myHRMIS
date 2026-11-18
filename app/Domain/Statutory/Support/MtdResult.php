<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

/**
 * PCB outcome. pcbNormal = MTD before zakat (after the RM10 rule); pcbNet = the
 * PCB line (normal net of zakat + additional PCB).
 */
final class MtdResult
{
    /**
     * @param  array<string, string|int|null>  $inputs  every variable, unrounded
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pcbNormal,
        public readonly string $pcbAdditional,
        public readonly string $pcbBeforeZakat,
        public readonly string $pcbNet,
        public readonly ?int $rateSetId,
        public readonly array $inputs,
    ) {}
}
