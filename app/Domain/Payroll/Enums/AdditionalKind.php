<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AdditionalKind: string
{
    use TranslatableEnum;

    case OneOff = 'one_off';
    case Recurring = 'recurring';

    public static function translationKey(): string
    {
        return 'payroll.enums.additional_kind';
    }
}
