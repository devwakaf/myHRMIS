<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum PayMode: string
{
    use TranslatableEnum;

    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';

    public static function translationKey(): string
    {
        return 'leave.enums.pay_mode';
    }
}
