<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CorrectionType: string
{
    use TranslatableEnum;

    case LwpReversal = 'lwp_reversal';
    case Amount = 'amount';

    public static function translationKey(): string
    {
        return 'payroll.enums.correction_type';
    }
}
