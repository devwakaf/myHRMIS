<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AdjustmentDirection: string
{
    use TranslatableEnum;

    case Add = 'add';
    case Reduce = 'reduce';

    public static function translationKey(): string
    {
        return 'leave.enums.adjustment_direction';
    }
}
