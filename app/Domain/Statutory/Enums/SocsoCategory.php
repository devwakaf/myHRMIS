<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum SocsoCategory: string
{
    use TranslatableEnum;

    case One = '1';
    case Two = '2';
    case None = 'none';

    public static function translationKey(): string
    {
        return 'statutory.enums.socso_category';
    }
}
