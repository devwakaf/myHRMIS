<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum SeparationReason: string
{
    use TranslatableEnum;

    case Resignation = 'resignation';
    case Termination = 'termination';
    case ContractEnd = 'contract_end';
    case Retirement = 'retirement';
    case Death = 'death';
    case TransferOut = 'transfer_out';
    case MutualSeparation = 'mutual_separation';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'core.enums.separation_reason';
    }
}
