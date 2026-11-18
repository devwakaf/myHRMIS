<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use BcMath\Number;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Display formats shared by PDFs and mails (A3). Mirrors resources/js/composables/useFormat.ts.
 */
final class Format
{
    public static function money(Number|string|int|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $value = (string) Rounding::halfUp($amount instanceof Number ? $amount : (string) $amount, 2);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
        $whole = self::groupThousands($whole);

        return ($negative ? '-' : '').'RM '.$whole.'.'.str_pad($fraction, 2, '0');
    }

    public static function date(CarbonInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        return ($date instanceof CarbonInterface ? $date : Carbon::parse($date))->format('d/m/Y');
    }

    public static function dateTime(CarbonInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        return ($date instanceof CarbonInterface ? $date : Carbon::parse($date))->format('d/m/Y H:i');
    }

    private static function groupThousands(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '0';
        }

        return strrev(implode(',', str_split(strrev($digits), 3)));
    }
}
