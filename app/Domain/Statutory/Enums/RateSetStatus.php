<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum RateSetStatus: string
{
    use TranslatableEnum;

    case Draft = 'draft';
    case Unverified = 'unverified';
    case Verified = 'verified';

    public static function translationKey(): string
    {
        return 'statutory.enums.rate_set_status';
    }
}
