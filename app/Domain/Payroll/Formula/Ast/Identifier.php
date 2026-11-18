<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula\Ast;

/** Component code or system variable (upper case). */
final class Identifier extends Node
{
    public function __construct(public readonly string $name) {}

    public function toArray(): array
    {
        return ['t' => 'id', 'n' => $this->name];
    }

    public function children(): array
    {
        return [];
    }
}
