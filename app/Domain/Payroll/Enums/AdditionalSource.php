<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AdditionalSource: string
{
    use TranslatableEnum;

    case Manual = 'manual';
    case Arrear = 'arrear';
    case Correction = 'correction';
    case LeaveEncashment = 'leave_encashment';
    case OvertimeClaim = 'overtime_claim';
    case Import = 'import';
    case OtherModule = 'other_module';

    public static function translationKey(): string
    {
        return 'payroll.enums.additional_source';
    }
}
