<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

use BcMath\Number;

/**
 * Plain in-memory scope: values by name plus full-cycle component values.
 */
final class ArrayScope implements FormulaScope
{
    /**
     * @param  array<string, Number|bool|string>  $values
     * @param  array<string, Number>  $fullValues
     */
    public function __construct(
        private array $values = [],
        private array $fullValues = [],
    ) {}

    public function set(string $name, Number|bool|string $value): void
    {
        $this->values[$name] = $value;
    }

    public function setFull(string $code, Number $value): void
    {
        $this->fullValues[$code] = $value;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function value(string $name): Number|bool|string
    {
        if (! array_key_exists($name, $this->values)) {
            throw FormulaError::of('unknown_identifier', ['name' => $name]);
        }

        return $this->values[$name];
    }

    public function full(string $code): Number
    {
        if (! array_key_exists($code, $this->fullValues)) {
            throw FormulaError::of('unknown_identifier', ['name' => $code]);
        }

        return $this->fullValues[$code];
    }
}
