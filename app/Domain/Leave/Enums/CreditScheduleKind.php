<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CreditScheduleKind: string
{
    use TranslatableEnum;

    case Accrual = 'accrual';
    case TierChange = 'tier_change';

    public static function translationKey(): string
    {
        return 'leave.enums.credit_schedule_kind';
    }
}
