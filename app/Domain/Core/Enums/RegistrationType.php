<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum RegistrationType: string
{
    use TranslatableEnum;

    case Company = 'company';
    case Business = 'business';
    case Llp = 'llp';
    case Society = 'society';
    case Government = 'government';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'core.enums.registration_type';
    }
}
