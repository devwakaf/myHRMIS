<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EncashmentRateBasis: string
{
    use TranslatableEnum;

    case OrgDailyRate = 'org_daily_rate';
    case Orp26 = 'orp_26';

    public static function translationKey(): string
    {
        return 'leave.enums.encashment_rate_basis';
    }
}
