<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula\Ast;

/** Number (decimal string), string or boolean literal. */
final class Literal extends Node
{
    public function __construct(
        public readonly string $kind,
        public readonly string|bool|null $value,
    ) {}

    public function toArray(): array
    {
        return ['t' => 'lit', 'k' => $this->kind, 'v' => $this->value];
    }

    public function children(): array
    {
        return [];
    }
}
