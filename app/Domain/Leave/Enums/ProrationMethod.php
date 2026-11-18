<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ProrationMethod: string
{
    use TranslatableEnum;

    case None = 'none';
    case Days = 'days';
    case CompletedMonths = 'completed_months';

    public static function translationKey(): string
    {
        return 'leave.enums.proration_method';
    }
}
