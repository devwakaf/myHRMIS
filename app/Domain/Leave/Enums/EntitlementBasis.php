<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EntitlementBasis: string
{
    use TranslatableEnum;

    case PerPeriod = 'per_period';
    case Earned = 'earned';
    case PerEvent = 'per_event';
    case Compensatory = 'compensatory';
    case Unlimited = 'unlimited';

    public static function translationKey(): string
    {
        return 'leave.enums.entitlement_basis';
    }
}
