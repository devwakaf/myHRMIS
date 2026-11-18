<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

/**
 * Allow-listed functions and reserved variables (spec-04 §3.3). Nothing outside
 * these lists can be called or read; there are no date, string or lookup functions.
 */
final class FunctionRegistry
{
    /** name => [min args, max args (null = unlimited)] */
    public const FUNCTIONS = [
        'MIN' => [2, null],
        'MAX' => [2, null],
        'ABS' => [1, 1],
        'ROUND' => [1, 2],
        'ROUNDUP' => [1, 2],
        'ROUNDDOWN' => [1, 2],
        'ROUNDTO' => [2, 2],
        'IF' => [3, 3],
        'SAFE_DIV' => [3, 3],
        'BETWEEN' => [3, 3],
        'FULL' => [1, 1],
    ];

    /** Numeric system variables. */
    public const NUMBER_VARIABLES = [
        'BASE', 'GROSS', 'PAYMENT_FRACTION', 'PAYMENT_DAYS', 'LWP_DAYS', 'ABSENT_DAYS', 'CALENDAR_DAYS',
        'WORKING_DAYS', 'DIVISOR', 'PERIOD_MONTH', 'PERIOD_YEAR', 'AGE', 'YEARS_OF_SERVICE',
    ];

    /** String system variables (codes / enum values). */
    public const STRING_VARIABLES = ['GRADE', 'DESIGNATION', 'DEPARTMENT', 'BRANCH', 'EMPLOYMENT_CATEGORY', 'CITIZENSHIP'];

    /** Boolean system variables. */
    public const BOOLEAN_VARIABLES = ['IS_NEW_JOINER', 'IS_LEAVER'];

    public const KEYWORDS = ['AND', 'OR', 'NOT', 'IN', 'TRUE', 'FALSE', 'DAN', 'ATAU', 'BUKAN'];

    public static function isFunction(string $name): bool
    {
        return array_key_exists($name, self::FUNCTIONS);
    }

    public static function isVariable(string $name): bool
    {
        return in_array($name, self::variables(), true);
    }

    /**
     * @return list<string>
     */
    public static function variables(): array
    {
        return array_merge(self::NUMBER_VARIABLES, self::STRING_VARIABLES, self::BOOLEAN_VARIABLES);
    }

    /** Words a component code may not use. */
    public static function isReserved(string $code): bool
    {
        $code = strtoupper($code);

        return self::isVariable($code) || self::isFunction($code) || in_array($code, self::KEYWORDS, true);
    }

    public static function checkArity(string $name, int $given): void
    {
        [$min, $max] = self::FUNCTIONS[$name];
        if ($given < $min || ($max !== null && $given > $max)) {
            $expected = $max === null ? $min.'+' : ($min === $max ? (string) $min : $min.'–'.$max);
            throw FormulaError::of('arg_count', ['name' => $name, 'expected' => $expected, 'given' => $given]);
        }
    }
}
