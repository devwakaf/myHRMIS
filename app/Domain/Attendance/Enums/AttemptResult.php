<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AttemptResult: string
{
    use TranslatableEnum;

    case Outside = 'outside';
    case LowAccuracy = 'low_accuracy';
    case NoLocation = 'no_location';
    case NoConsent = 'no_consent';
    case RateLimited = 'rate_limited';

    public static function translationKey(): string
    {
        return 'attendance.enums.attempt_result';
    }
}
