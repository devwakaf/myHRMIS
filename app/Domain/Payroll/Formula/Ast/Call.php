<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula\Ast;

/** Call of an allow-listed function (FunctionRegistry). */
final class Call extends Node
{
    /**
     * @param  list<Node>  $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
    ) {}

    public function toArray(): array
    {
        return ['t' => 'call', 'n' => $this->name, 'args' => array_map(fn (Node $a) => $a->toArray(), $this->args)];
    }

    public function children(): array
    {
        return $this->args;
    }
}
