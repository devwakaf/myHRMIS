<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CitizenshipGroup: string
{
    use TranslatableEnum;

    case Citizen = 'citizen';
    case PrOrPre1998 = 'pr_or_pre1998';
    case Foreign = 'foreign';

    public static function translationKey(): string
    {
        return 'statutory.enums.citizenship_group';
    }
}
