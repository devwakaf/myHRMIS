<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula\Ast;

use App\Domain\Payroll\Formula\FormulaError;

/**
 * Immutable, JSON-serialisable formula AST (spec-04 §3.1). Stored on structure
 * rows as `formula_ast` / `condition_ast` and decoded with fromArray().
 */
abstract class Node
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @return list<Node>
     */
    abstract public function children(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): Node
    {
        $type = $data['t'] ?? null;

        return match ($type) {
            'lit' => new Literal((string) ($data['k'] ?? 'num'), $data['v'] ?? null),
            'id' => new Identifier((string) $data['n']),
            'un' => new Unary((string) $data['op'], self::child($data['a'] ?? null)),
            'bin' => new Binary((string) $data['op'], self::child($data['l'] ?? null), self::child($data['r'] ?? null)),
            'in' => new InList(self::child($data['a'] ?? null), array_map(
                fn ($item) => self::literal($item),
                array_values((array) ($data['list'] ?? [])),
            ), (bool) ($data['neg'] ?? false)),
            'call' => new Call((string) $data['n'], array_map(
                fn ($arg) => self::child($arg),
                array_values((array) ($data['args'] ?? [])),
            )),
            default => throw FormulaError::of('syntax', ['pos' => 0, 'expected' => 'AST']),
        };
    }

    public function depth(): int
    {
        $max = 0;
        foreach ($this->children() as $child) {
            $max = max($max, $child->depth());
        }

        return $max + 1;
    }

    public function count(): int
    {
        $n = 1;
        foreach ($this->children() as $child) {
            $n += $child->count();
        }

        return $n;
    }

    private static function child(mixed $data): Node
    {
        if (! is_array($data)) {
            throw FormulaError::of('syntax', ['pos' => 0, 'expected' => 'AST']);
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    private static function literal(mixed $data): Literal
    {
        $node = self::child($data);
        if (! $node instanceof Literal) {
            throw FormulaError::of('syntax', ['pos' => 0, 'expected' => 'literal']);
        }

        return $node;
    }
}
