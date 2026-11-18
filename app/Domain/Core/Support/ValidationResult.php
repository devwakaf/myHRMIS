<?php

declare(strict_types=1);

namespace App\Domain\Core\Support;

/**
 * Normalised data plus non-blocking warnings from a business validator.
 */
final class ValidationResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $data,
        public readonly array $warnings = [],
    ) {}
}
