<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum DeclarationType: string
{
    use TranslatableEnum;

    case Tp1 = 'tp1';
    case Tp3 = 'tp3';
    case ZakatExternal = 'zakat_external';

    public static function translationKey(): string
    {
        return 'payroll.enums.declaration_type';
    }
}
