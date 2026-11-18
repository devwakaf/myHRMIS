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
use App\Domain\Shared\Money\Rounding;
use BcMath\Number;

/**
 * Tree-walking evaluator over BcMath\Number at scale 10 (spec-04 §3.1, §3.4).
 * Pure: no PHP evaluation, no dynamic calls, no property access. Rounding
 * functions go through the shared Rounding service.
 */
final class Evaluator
{
    public const SCALE = 10;

    /** @param string $code component being evaluated (for error messages) */
    public function __construct(private readonly string $code = '') {}

    public function evaluate(Node $node, FormulaScope $scope): Number|bool|string
    {
        return match (true) {
            $node instanceof Literal => $this->literal($node),
            $node instanceof Identifier => $scope->value($node->name),
            $node instanceof Unary => $this->unary($node, $scope),
            $node instanceof Binary => $this->binary($node, $scope),
            $node instanceof InList => $this->in($node, $scope),
            $node instanceof Call => $this->call($node, $scope),
            default => throw FormulaError::of('syntax', ['pos' => 0, 'expected' => 'AST']),
        };
    }

    public function number(Node $node, FormulaScope $scope): Number
    {
        $value = $this->evaluate($node, $scope);
        if (! $value instanceof Number) {
            throw FormulaError::of('type_mismatch', ['op' => '=', 'type' => self::typeName($value)]);
        }

        return $value;
    }

    public function condition(Node $node, FormulaScope $scope): bool
    {
        $value = $this->evaluate($node, $scope);
        if (! is_bool($value)) {
            throw FormulaError::of('condition_not_boolean');
        }

        return $value;
    }

    public static function typeName(Number|bool|string $value): string
    {
        return match (true) {
            $value instanceof Number => (string) __('payroll.formula.types.number'),
            is_bool($value) => (string) __('payroll.formula.types.boolean'),
            default => (string) __('payroll.formula.types.string'),
        };
    }

    private function literal(Literal $node): Number|bool|string
    {
        return match ($node->kind) {
            'num' => new Number((string) $node->value),
            'bool' => (bool) $node->value,
            default => (string) $node->value,
        };
    }

    private function unary(Unary $node, FormulaScope $scope): Number|bool
    {
        $value = $this->evaluate($node->operand, $scope);

        if ($node->op === 'NOT') {
            if (! is_bool($value)) {
                throw FormulaError::of('type_mismatch', ['op' => 'NOT', 'type' => self::typeName($value)]);
            }

            return ! $value;
        }

        if (! $value instanceof Number) {
            throw FormulaError::of('type_mismatch', ['op' => $node->op, 'type' => self::typeName($value)]);
        }

        return $node->op === '-' ? (new Number('0'))->sub($value) : $value;
    }

    private function binary(Binary $node, FormulaScope $scope): Number|bool
    {
        if ($node->op === 'AND' || $node->op === 'OR') {
            $left = $this->evaluate($node->left, $scope);
            if (! is_bool($left)) {
                throw FormulaError::of('type_mismatch', ['op' => $node->op, 'type' => self::typeName($left)]);
            }
            if ($node->op === 'AND' && ! $left) {
                return false;
            }
            if ($node->op === 'OR' && $left) {
                return true;
            }
            $right = $this->evaluate($node->right, $scope);
            if (! is_bool($right)) {
                throw FormulaError::of('type_mismatch', ['op' => $node->op, 'type' => self::typeName($right)]);
            }

            return $right;
        }

        $left = $this->evaluate($node->left, $scope);
        $right = $this->evaluate($node->right, $scope);

        if (in_array($node->op, ['=', '!='], true)) {
            if (gettype($left) !== gettype($right) || ($left instanceof Number) !== ($right instanceof Number)) {
                throw FormulaError::of('type_mismatch', ['op' => $node->op, 'type' => self::typeName($right)]);
            }
            $equal = $left instanceof Number && $right instanceof Number ? $left->compare($right) === 0 : $left === $right;

            return $node->op === '=' ? $equal : ! $equal;
        }

        if (! $left instanceof Number) {
            throw FormulaError::of('type_mismatch', ['op' => $node->op, 'type' => self::typeName($left)]);
        }
        if (! $right instanceof Number) {
            throw FormulaError::of('type_mismatch', ['op' => $node->op, 'type' => self::typeName($right)]);
        }

        return match ($node->op) {
            '+' => $left->add($right),
            '-' => $left->sub($right),
            '*' => $left->mul($right, self::SCALE),
            '/' => $this->divide($left, $right),
            '^' => $left->pow($right, self::SCALE),
            '<' => $left->compare($right) < 0,
            '<=' => $left->compare($right) <= 0,
            '>' => $left->compare($right) > 0,
            '>=' => $left->compare($right) >= 0,
            default => throw FormulaError::of('syntax', ['pos' => 0, 'expected' => 'operator']),
        };
    }

    private function in(InList $node, FormulaScope $scope): bool
    {
        $value = $this->evaluate($node->operand, $scope);
        $found = false;
        foreach ($node->items as $item) {
            $candidate = $this->literal($item);
            if ($value instanceof Number && $candidate instanceof Number) {
                $found = $value->compare($candidate) === 0;
            } elseif (! $value instanceof Number && ! $candidate instanceof Number) {
                $found = $value === $candidate;
            }
            if ($found) {
                break;
            }
        }

        return $node->negated ? ! $found : $found;
    }

    private function call(Call $node, FormulaScope $scope): Number|bool
    {
        FunctionRegistry::checkArity($node->name, count($node->args));

        if ($node->name === 'IF') {
            $condition = $this->evaluate($node->args[0], $scope);
            if (! is_bool($condition)) {
                throw FormulaError::of('condition_not_boolean');
            }
            $result = $this->evaluate($condition ? $node->args[1] : $node->args[2], $scope);
            if (is_string($result)) {
                throw FormulaError::of('type_mismatch', ['op' => 'IF', 'type' => self::typeName($result)]);
            }

            return $result;
        }

        if ($node->name === 'FULL') {
            $arg = $node->args[0];
            if (! $arg instanceof Identifier) {
                throw FormulaError::of('full_argument');
            }

            return $scope->full($arg->name);
        }

        $args = array_map(fn (Node $a) => $this->number($a, $scope), $node->args);

        return match ($node->name) {
            'MIN' => array_reduce($args, fn (?Number $carry, Number $n) => $carry === null || $n->compare($carry) < 0 ? $n : $carry),
            'MAX' => array_reduce($args, fn (?Number $carry, Number $n) => $carry === null || $n->compare($carry) > 0 ? $n : $carry),
            'ABS' => $args[0]->compare(0) < 0 ? (new Number('0'))->sub($args[0]) : $args[0],
            'ROUND' => Rounding::halfUp($args[0], $this->places($args[1] ?? null, 2)),
            'ROUNDUP' => Rounding::awayFromZero($args[0], $this->places($args[1] ?? null, 0)),
            'ROUNDDOWN' => Rounding::truncate($args[0], $this->places($args[1] ?? null, 0)),
            'ROUNDTO' => $args[1]->compare(0) <= 0
                ? throw FormulaError::of('division_by_zero', ['code' => $this->code])
                : Rounding::toStep($args[0], $args[1], \RoundingMode::HalfAwayFromZero, self::SCALE),
            'SAFE_DIV' => $args[1]->compare(0) === 0 ? $args[2] : $args[0]->div($args[1], self::SCALE),
            'BETWEEN' => $args[0]->compare($args[1]) >= 0 && $args[0]->compare($args[2]) <= 0,
            default => throw FormulaError::of('unknown_function', ['name' => $node->name]),
        };
    }

    private function divide(Number $left, Number $right): Number
    {
        if ($right->compare(0) === 0) {
            throw FormulaError::of('division_by_zero', ['code' => $this->code]);
        }

        return $left->div($right, self::SCALE);
    }

    private function places(?Number $value, int $default): int
    {
        if ($value === null) {
            return $default;
        }

        return max(0, min(6, (int) (string) Rounding::truncate($value, 0)));
    }
}
