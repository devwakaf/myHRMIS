<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum NoLocationPolicy: string
{
    use TranslatableEnum;

    case Allow = 'allow';
    case Deny = 'deny';

    public static function translationKey(): string
    {
        return 'attendance.enums.no_location_policy';
    }
}
