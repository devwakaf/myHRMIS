<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CitizenshipStatus: string
{
    use TranslatableEnum;

    case Citizen = 'citizen';
    case PermanentResident = 'permanent_resident';
    case Foreign = 'foreign';
    case ForeignPre1998 = 'foreign_pre_1998';

    public static function translationKey(): string
    {
        return 'core.enums.citizenship_status';
    }
}
