<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum OtEligibilityMode: string
{
    use TranslatableEnum;

    case Auto = 'auto';
    case Always = 'always';
    case Never = 'never';

    public static function translationKey(): string
    {
        return 'attendance.enums.ot_eligibility_mode';
    }
}
