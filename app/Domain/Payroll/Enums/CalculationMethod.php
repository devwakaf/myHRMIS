<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CalculationMethod: string
{
    use TranslatableEnum;

    case Fixed = 'fixed';
    case Formula = 'formula';
    case Statutory = 'statutory';
    case Input = 'input';

    public static function translationKey(): string
    {
        return 'payroll.enums.calculation_method';
    }
}
