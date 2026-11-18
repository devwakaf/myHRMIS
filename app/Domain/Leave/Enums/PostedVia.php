<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum PostedVia: string
{
    use TranslatableEnum;

    case Scheduler = 'scheduler';
    case ManualRetry = 'manual_retry';
    case Generation = 'generation';

    public static function translationKey(): string
    {
        return 'leave.enums.posted_via';
    }
}
