<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum Lindung24Status: string
{
    use TranslatableEnum;

    case OptedIn = 'opted_in';
    case OptedOut = 'opted_out';

    public static function translationKey(): string
    {
        return 'core.enums.lindung24_status';
    }
}
