<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

/**
 * Hand-written lexer for the payroll formula language (spec-04 §3.2). Produces
 * number, string, identifier (upper-cased) and operator tokens; never evaluates.
 */
final class Lexer
{
    public const MAX_LENGTH = 500;

    public const MAX_STRING = 50;

    public const MAX_INTEGER_DIGITS = 15;

    private const OPERATORS = ['==', '!=', '<>', '<=', '>=', '+', '-', '*', '/', '^', '%', '(', ')', '[', ']', ',', '=', '<', '>'];

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw FormulaError::of('too_long', ['max' => self::MAX_LENGTH]);
        }

        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $c = $source[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            if (ctype_digit($c) || ($c === '.' && $i + 1 < $length && ctype_digit($source[$i + 1]))) {
                $start = $i;
                while ($i < $length && ctype_digit($source[$i])) {
                    $i++;
                }
                $intDigits = $i - $start;
                if ($i < $length && $source[$i] === '.') {
                    $i++;
                    if ($i >= $length || ! ctype_digit($source[$i])) {
                        throw FormulaError::of('syntax', ['pos' => $i + 1, 'expected' => (string) __('payroll.formula.expect.digit')]);
                    }
                    while ($i < $length && ctype_digit($source[$i])) {
                        $i++;
                    }
                }
                if ($intDigits > self::MAX_INTEGER_DIGITS) {
                    throw FormulaError::of('number_too_long', ['max' => self::MAX_INTEGER_DIGITS]);
                }
                $text = substr($source, $start, $i - $start);
                $tokens[] = new Token('num', str_starts_with($text, '.') ? '0'.$text : $text, $start + 1);

                continue;
            }

            if ($c === "'") {
                $start = $i;
                $i++;
                $value = '';
                while ($i < $length && $source[$i] !== "'") {
                    $value .= $source[$i];
                    $i++;
                }
                if ($i >= $length) {
                    throw FormulaError::of('syntax', ['pos' => $start + 1, 'expected' => "'"]);
                }
                $i++;
                if (mb_strlen($value) > self::MAX_STRING) {
                    throw FormulaError::of('string_too_long', ['max' => self::MAX_STRING]);
                }
                $tokens[] = new Token('str', $value, $start + 1);

                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($source[$i]) || $source[$i] === '_')) {
                    $i++;
                }
                $tokens[] = new Token('ident', strtoupper(substr($source, $start, $i - $start)), $start + 1);

                continue;
            }

            $matched = null;
            foreach (self::OPERATORS as $op) {
                if (substr($source, $i, strlen($op)) === $op) {
                    $matched = $op;
                    break;
                }
            }
            if ($matched === null) {
                throw FormulaError::of('syntax', ['pos' => $i + 1, 'expected' => (string) __('payroll.formula.expect.expression')]);
            }
            $tokens[] = new Token('op', $matched, $i + 1);
            $i += strlen($matched);
        }

        $tokens[] = new Token('eof', '', $length + 1);

        return $tokens;
    }
}
