<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Payroll\Enums\LineSource;

/** A computed slip line (amounts "0.00" strings). */
final class LineResult
{
    /**
     * @param  array<string, bool>  $flags
     * @param  list<array{id: int, amount: string}>  $applications
     */
    public function __construct(
        public readonly ComponentSpec $component,
        public readonly string $amount,
        public readonly string $fullCycle,
        public readonly string $structureAmount,
        public readonly string $additionalAmount,
        public readonly LineSource $source,
        public readonly array $flags,
        public readonly array $applications = [],
        public readonly ?string $quantity = null,
        public readonly string $exemptAmount = '0.00',
        public readonly ?string $note = null,
        public readonly bool $visible = true,
    ) {}

    public function flag(string $name): bool
    {
        return $this->flags[$name] ?? false;
    }
}
