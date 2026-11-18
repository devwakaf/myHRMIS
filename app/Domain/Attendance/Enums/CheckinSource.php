<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CheckinSource: string
{
    use TranslatableEnum;

    case Web = 'web';
    case Mobile = 'mobile';
    case Device = 'device';
    case Import = 'import';
    case HrManual = 'hr_manual';

    public static function translationKey(): string
    {
        return 'attendance.enums.checkin_source';
    }
}
