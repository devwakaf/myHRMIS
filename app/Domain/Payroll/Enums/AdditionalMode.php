<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AdditionalMode: string
{
    use TranslatableEnum;

    case Add = 'add';
    case Replace = 'replace';

    public static function translationKey(): string
    {
        return 'payroll.enums.additional_mode';
    }
}
