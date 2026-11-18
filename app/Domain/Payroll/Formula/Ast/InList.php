<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula\Ast;

/** `x IN [a, b]` / `x NOT IN [a, b]` with literal list items. */
final class InList extends Node
{
    /**
     * @param  list<Literal>  $items
     */
    public function __construct(
        public readonly Node $operand,
        public readonly array $items,
        public readonly bool $negated,
    ) {}

    public function toArray(): array
    {
        return [
            't' => 'in',
            'a' => $this->operand->toArray(),
            'list' => array_map(fn (Literal $l) => $l->toArray(), $this->items),
            'neg' => $this->negated,
        ];
    }

    public function children(): array
    {
        return [$this->operand, ...$this->items];
    }
}
