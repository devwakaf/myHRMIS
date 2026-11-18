<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EncashmentPurpose: string
{
    use TranslatableEnum;

    case PeriodEnd = 'period_end';
    case Separation = 'separation';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'leave.enums.encashment_purpose';
    }
}
