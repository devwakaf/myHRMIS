<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum RemittanceScheme: string
{
    use TranslatableEnum;

    case Kwsp = 'kwsp';
    case Perkeso = 'perkeso';
    case Pcb = 'pcb';
    case Hrdc = 'hrdc';
    case Zakat = 'zakat';

    public static function translationKey(): string
    {
        return 'payroll.enums.remittance_scheme';
    }
}
