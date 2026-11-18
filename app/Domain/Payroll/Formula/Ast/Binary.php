<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula\Ast;

/** Infix operator: + - * / ^ = != < <= > >= AND OR. */
final class Binary extends Node
{
    public function __construct(
        public readonly string $op,
        public readonly Node $left,
        public readonly Node $right,
    ) {}

    public function toArray(): array
    {
        return ['t' => 'bin', 'op' => $this->op, 'l' => $this->left->toArray(), 'r' => $this->right->toArray()];
    }

    public function children(): array
    {
        return [$this->left, $this->right];
    }
}
