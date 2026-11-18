<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

/**
 * Lexer token. $type is one of: num, str, ident, op, eof. $pos is 1-based.
 */
final class Token
{
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly int $pos,
    ) {}

    public function is(string $type, ?string $value = null): bool
    {
        return $this->type === $type && ($value === null || $this->value === $value);
    }
}
