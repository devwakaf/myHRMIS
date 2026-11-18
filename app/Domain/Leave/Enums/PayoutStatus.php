<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum PayoutStatus: string
{
    use TranslatableEnum;

    case AwaitingPayroll = 'awaiting_payroll';
    case InPayroll = 'in_payroll';
    case Paid = 'paid';

    public static function translationKey(): string
    {
        return 'leave.enums.payout_status';
    }
}
