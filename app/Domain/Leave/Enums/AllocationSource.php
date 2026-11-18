<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AllocationSource: string
{
    use TranslatableEnum;

    case Policy = 'policy';
    case Manual = 'manual';
    case Compensatory = 'compensatory';

    public static function translationKey(): string
    {
        return 'leave.enums.allocation_source';
    }
}
