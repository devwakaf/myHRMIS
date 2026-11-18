<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Files;

use App\Domain\Statutory\Exceptions\StatutoryRateException;

/**
 * Fixed-width record writer shared by the statutory files (spec-04 §6): left or
 * right justification, pad character, exact record length check and ASCII
 * transliteration (non-ASCII removed after transliteration, upper case).
 */
final class FixedWidthWriter
{
    /**
     * @param  list<array{start: int, length: int, value: string, align?: string, pad?: string}>  $fields
     */
    public static function record(int $length, array $fields): string
    {
        $line = str_repeat(' ', $length);
        foreach ($fields as $field) {
            $width = $field['length'];
            $value = self::fit($field['value'], $width, $field['align'] ?? 'left', $field['pad'] ?? ' ');
            $line = substr_replace($line, $value, $field['start'] - 1, $width);
        }
        if (strlen($line) !== $length) {
            throw new StatutoryRateException('statutory.errors.record_length', ['expected' => $length, 'actual' => strlen($line)]);
        }

        return $line;
    }

    public static function fit(string $value, int $width, string $align = 'left', string $pad = ' '): string
    {
        $value = substr($value, 0, $width);
        $pad = $pad === '' ? ' ' : $pad[0];

        return str_pad($value, $width, $pad, $align === 'right' ? STR_PAD_LEFT : STR_PAD_RIGHT);
    }

    /** "Siti Nur'ain Bt. Ábdul" → "SITI NUR'AIN BT. ABDUL" (ASCII only). */
    public static function ascii(string $value): string
    {
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
        $converted = $converted === false ? $value : $converted;
        $converted = (string) preg_replace('/[^\x20-\x7E]/', '', $converted);

        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $converted)));
    }

    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }
}
