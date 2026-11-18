<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum TierBasis: string
{
    use TranslatableEnum;

    case ProrataByAnniversary = 'prorata_by_anniversary';
    case ServiceAtPeriodStart = 'service_at_period_start';
    case ServiceAtPeriodEnd = 'service_at_period_end';

    public static function translationKey(): string
    {
        return 'leave.enums.tier_basis';
    }
}
