<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum RateScheme: string
{
    use TranslatableEnum;

    case Epf = 'epf';
    case Socso = 'socso';
    case Eis = 'eis';
    case Mtd = 'mtd';
    case TaxReliefs = 'tax_reliefs';
    case Hrdc = 'hrdc';
    case MinimumWage = 'minimum_wage';

    public static function translationKey(): string
    {
        return 'statutory.enums.rate_scheme';
    }
}
