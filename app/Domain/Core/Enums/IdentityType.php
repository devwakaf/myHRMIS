<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum IdentityType: string
{
    use TranslatableEnum;

    case MyKad = 'mykad';
    case Passport = 'passport';
    case Military = 'military';
    case Police = 'police';

    public static function translationKey(): string
    {
        return 'core.enums.identity_type';
    }
}
