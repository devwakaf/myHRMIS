<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum FormatStatus: string
{
    use TranslatableEnum;

    case Unverified = 'unverified';
    case Verified = 'verified';

    public static function translationKey(): string
    {
        return 'statutory.enums.format_status';
    }
}
