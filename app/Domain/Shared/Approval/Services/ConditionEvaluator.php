<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Services;

use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Enums\AttributeType;

/**
 * Evaluates step conditions: a list of rules that must all hold (A6.4).
 * Never evaluated as code; operators are a fixed set.
 */
final class ConditionEvaluator
{
    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in'];

    public function __construct(private readonly ApprovalTypeRegistry $registry) {}

    /**
     * @param  list<array{attribute: string, operator: string, value: mixed}>|null  $condition
     * @param  array<string, mixed>  $attributes
     */
    public function passes(?array $condition, array $attributes): bool
    {
        if ($condition === null || $condition === []) {
            return true;
        }

        foreach ($condition as $rule) {
            if (! $this->rulePasses($rule, $attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validation errors for a condition against a type's catalogue (empty = valid).
     *
     * @param  list<array<string, mixed>>|null  $condition
     * @return list<string>
     */
    public function validate(string $type, ?array $condition): array
    {
        if ($condition === null) {
            return [];
        }

        $catalogue = $this->registry->catalogue($type);
        $enumOptions = $this->registry->enumOptions($type);
        $errors = [];

        foreach ($condition as $i => $rule) {
            $attribute = (string) ($rule['attribute'] ?? '');
            $operator = (string) ($rule['operator'] ?? '');
            $value = $rule['value'] ?? null;

            if (! array_key_exists($attribute, $catalogue)) {
                $errors[] = (string) __('approval.errors.unknown_attribute', ['attribute' => $attribute, 'n' => $i + 1]);

                continue;
            }

            if (! in_array($operator, self::OPERATORS, true)) {
                $errors[] = (string) __('approval.errors.unknown_operator', ['operator' => $operator, 'n' => $i + 1]);

                continue;
            }

            $type = $catalogue[$attribute];
            $isList = in_array($operator, ['in', 'not_in'], true);
            $values = $isList ? (is_array($value) ? $value : [$value]) : [$value];

            if (in_array($operator, ['>', '>=', '<', '<='], true) && $type !== AttributeType::Number) {
                $errors[] = (string) __('approval.errors.operator_not_numeric', ['attribute' => $attribute]);
            }

            foreach ($values as $v) {
                if ($type === AttributeType::Number && ! is_numeric($v)) {
                    $errors[] = (string) __('approval.errors.value_not_numeric', ['attribute' => $attribute]);
                }
                if ($type === AttributeType::Enum && isset($enumOptions[$attribute]) && ! in_array((string) $v, $enumOptions[$attribute], true)) {
                    $errors[] = (string) __('approval.errors.value_not_allowed', ['attribute' => $attribute, 'value' => (string) $v]);
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $attributes
     */
    private function rulePasses(array $rule, array $attributes): bool
    {
        $attribute = (string) ($rule['attribute'] ?? '');
        $operator = (string) ($rule['operator'] ?? '=');
        $expected = $rule['value'] ?? null;

        if (! array_key_exists($attribute, $attributes)) {
            return false;
        }

        $actual = $attributes[$attribute];

        return match ($operator) {
            '=' => $this->equals($actual, $expected),
            '!=' => ! $this->equals($actual, $expected),
            '>' => is_numeric($actual) && is_numeric($expected) && bccomp((string) $actual, (string) $expected, 6) > 0,
            '>=' => is_numeric($actual) && is_numeric($expected) && bccomp((string) $actual, (string) $expected, 6) >= 0,
            '<' => is_numeric($actual) && is_numeric($expected) && bccomp((string) $actual, (string) $expected, 6) < 0,
            '<=' => is_numeric($actual) && is_numeric($expected) && bccomp((string) $actual, (string) $expected, 6) <= 0,
            'in' => $this->inList($actual, $expected),
            'not_in' => ! $this->inList($actual, $expected),
            default => false,
        };
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return $this->toBool($actual) === $this->toBool($expected);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return bccomp((string) $actual, (string) $expected, 6) === 0;
        }

        return (string) $actual === (string) $expected;
    }

    private function inList(mixed $actual, mixed $expected): bool
    {
        foreach ((array) $expected as $candidate) {
            if ($this->equals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'ya'], true);
        }

        return (bool) $value;
    }
}
