<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum PcbRemunerationType: string
{
    use TranslatableEnum;

    case Normal = 'normal';
    case Additional = 'additional';

    public static function translationKey(): string
    {
        return 'payroll.enums.pcb_type';
    }
}
