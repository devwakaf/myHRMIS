<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum SalaryPaymentMode: string
{
    use TranslatableEnum;

    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Cash = 'cash';

    public static function translationKey(): string
    {
        return 'core.enums.salary_payment_mode';
    }
}
