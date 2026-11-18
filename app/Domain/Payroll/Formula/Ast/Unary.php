<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula\Ast;

/** Prefix operator: '-', '+' or 'NOT'. */
final class Unary extends Node
{
    public function __construct(
        public readonly string $op,
        public readonly Node $operand,
    ) {}

    public function toArray(): array
    {
        return ['t' => 'un', 'op' => $this->op, 'a' => $this->operand->toArray()];
    }

    public function children(): array
    {
        return [$this->operand];
    }
}
