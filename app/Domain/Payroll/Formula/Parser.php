<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

use App\Domain\Payroll\Formula\Ast\Binary;
use App\Domain\Payroll\Formula\Ast\Call;
use App\Domain\Payroll\Formula\Ast\Identifier;
use App\Domain\Payroll\Formula\Ast\InList;
use App\Domain\Payroll\Formula\Ast\Literal;
use App\Domain\Payroll\Formula\Ast\Node;
use App\Domain\Payroll\Formula\Ast\Unary;
use BcMath\Number;

/**
 * Recursive-descent parser for the grammar of spec-04 §3.2. Builds an immutable
 * AST and enforces the limits (length 500, depth 32, 200 nodes, integer exponent
 * 0–10). Function names are checked against FunctionRegistry at parse time.
 */
final class Parser
{
    public const MAX_DEPTH = 32;

    public const MAX_NODES = 200;

    /** @var list<Token> */
    private array $tokens = [];

    private int $p = 0;

    private int $depth = 0;

    public function parse(string $source): Node
    {
        $this->tokens = (new Lexer)->tokenize($source);
        $this->p = 0;
        $this->depth = 0;

        if ($this->peek()->is('eof')) {
            throw FormulaError::of('syntax', ['pos' => 1, 'expected' => (string) __('payroll.formula.expect.expression')]);
        }

        $node = $this->orExpr();
        $this->expect('eof', null, (string) __('payroll.formula.expect.end'));

        if ($node->depth() > self::MAX_DEPTH) {
            throw FormulaError::of('too_deep', ['max' => self::MAX_DEPTH]);
        }
        if ($node->count() > self::MAX_NODES) {
            throw FormulaError::of('too_many_nodes', ['max' => self::MAX_NODES]);
        }

        return $node;
    }

    private function orExpr(): Node
    {
        $left = $this->andExpr();
        while ($this->keyword('OR', 'ATAU')) {
            $left = new Binary('OR', $left, $this->andExpr());
        }

        return $left;
    }

    private function andExpr(): Node
    {
        $left = $this->notExpr();
        while ($this->keyword('AND', 'DAN')) {
            $left = new Binary('AND', $left, $this->notExpr());
        }

        return $left;
    }

    private function notExpr(): Node
    {
        if ($this->keyword('NOT', 'BUKAN')) {
            return $this->nested(fn () => new Unary('NOT', $this->notExpr()));
        }

        return $this->comparison();
    }

    private function comparison(): Node
    {
        $left = $this->additive();
        $t = $this->peek();

        if ($t->is('op') && in_array($t->value, ['=', '==', '!=', '<>', '<', '<=', '>', '>='], true)) {
            $this->p++;
            $op = match ($t->value) {
                '==' => '=',
                '<>' => '!=',
                default => $t->value,
            };

            return new Binary($op, $left, $this->additive());
        }

        $negated = false;
        if ($t->is('ident') && in_array($t->value, ['NOT', 'BUKAN'], true) && $this->peek(1)->is('ident', 'IN')) {
            $this->p++;
            $negated = true;
        }
        if ($this->keyword('IN')) {
            return new InList($left, $this->literalList(), $negated);
        }

        return $left;
    }

    private function additive(): Node
    {
        $left = $this->term();
        while ($this->peek()->is('op', '+') || $this->peek()->is('op', '-')) {
            $op = $this->advance()->value;
            $left = new Binary($op, $left, $this->term());
        }

        return $left;
    }

    private function term(): Node
    {
        $left = $this->unary();
        while ($this->peek()->is('op', '*') || $this->peek()->is('op', '/')) {
            $op = $this->advance()->value;
            $left = new Binary($op, $left, $this->unary());
        }

        return $left;
    }

    private function unary(): Node
    {
        if ($this->peek()->is('op', '-') || $this->peek()->is('op', '+')) {
            $op = $this->advance()->value;

            return $this->nested(fn () => new Unary($op, $this->unary()));
        }

        return $this->power();
    }

    private function power(): Node
    {
        $base = $this->primary();
        if ($this->peek()->is('op', '^')) {
            $this->p++;
            $t = $this->peek();
            if (! $t->is('num') || str_contains($t->value, '.') || (int) $t->value > 10) {
                throw FormulaError::of('exponent', ['pos' => $t->pos]);
            }
            $this->p++;

            return new Binary('^', $base, new Literal('num', (string) (int) $t->value));
        }

        return $base;
    }

    private function primary(): Node
    {
        $t = $this->peek();

        if ($t->is('num')) {
            $this->p++;
            if ($this->peek()->is('op', '%')) {
                $this->p++;

                return new Literal('num', (string) (new Number($t->value))->div(100, 10));
            }

            return new Literal('num', $t->value);
        }

        if ($t->is('str')) {
            $this->p++;

            return new Literal('str', $t->value);
        }

        if ($t->is('op', '(')) {
            $this->p++;
            $node = $this->nested(fn () => $this->orExpr());
            $this->expect('op', ')', ')');

            return $node;
        }

        if ($t->is('ident')) {
            if (in_array($t->value, ['TRUE', 'FALSE'], true)) {
                $this->p++;

                return new Literal('bool', $t->value === 'TRUE');
            }

            if ($this->peek(1)->is('op', '(')) {
                return $this->call();
            }

            if (in_array($t->value, FunctionRegistry::KEYWORDS, true)) {
                throw FormulaError::of('syntax', ['pos' => $t->pos, 'expected' => (string) __('payroll.formula.expect.expression')]);
            }

            $this->p++;

            return new Identifier($t->value);
        }

        throw FormulaError::of('syntax', ['pos' => $t->pos, 'expected' => (string) __('payroll.formula.expect.expression')]);
    }

    private function call(): Node
    {
        $name = $this->advance();
        if (! FunctionRegistry::isFunction($name->value)) {
            throw FormulaError::of('unknown_function', ['name' => $name->value]);
        }
        $this->expect('op', '(', '(');

        $args = [];
        if (! $this->peek()->is('op', ')')) {
            do {
                $args[] = $this->nested(fn () => $this->orExpr());
            } while ($this->peek()->is('op', ',') && $this->advance()->is('op'));
        }
        $this->expect('op', ')', ')');

        FunctionRegistry::checkArity($name->value, count($args));
        if ($name->value === 'FULL' && ! $args[0] instanceof Identifier) {
            throw FormulaError::of('full_argument');
        }

        return new Call($name->value, $args);
    }

    /**
     * @return list<Literal>
     */
    private function literalList(): array
    {
        $this->expect('op', '[', '[');
        $items = [];
        do {
            $t = $this->peek();
            $negative = false;
            if ($t->is('op', '-')) {
                $negative = true;
                $this->p++;
                $t = $this->peek();
            }
            if ($t->is('num')) {
                $items[] = new Literal('num', ($negative ? '-' : '').$t->value);
            } elseif ($t->is('str') && ! $negative) {
                $items[] = new Literal('str', $t->value);
            } elseif ($t->is('ident') && in_array($t->value, ['TRUE', 'FALSE'], true) && ! $negative) {
                $items[] = new Literal('bool', $t->value === 'TRUE');
            } else {
                throw FormulaError::of('syntax', ['pos' => $t->pos, 'expected' => (string) __('payroll.formula.expect.literal')]);
            }
            $this->p++;
        } while ($this->peek()->is('op', ',') && $this->advance()->is('op'));
        $this->expect('op', ']', ']');

        return $items;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function nested(callable $callback): mixed
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw FormulaError::of('too_deep', ['max' => self::MAX_DEPTH]);
        }

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    private function keyword(string ...$words): bool
    {
        $t = $this->peek();
        if ($t->is('ident') && in_array($t->value, $words, true)) {
            $this->p++;

            return true;
        }

        return false;
    }

    private function peek(int $ahead = 0): Token
    {
        return $this->tokens[$this->p + $ahead] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function advance(): Token
    {
        return $this->tokens[$this->p++];
    }

    private function expect(string $type, ?string $value, string $label): Token
    {
        $t = $this->peek();
        if (! $t->is($type, $value)) {
            throw FormulaError::of('syntax', ['pos' => $t->pos, 'expected' => $label]);
        }
        $this->p++;

        return $t;
    }
}
