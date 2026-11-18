<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum PaymentStatus: string
{
    use TranslatableEnum;

    case Unpaid = 'unpaid';
    case Withheld = 'withheld';
    case Paid = 'paid';

    public static function translationKey(): string
    {
        return 'payroll.enums.payment_status';
    }
}
