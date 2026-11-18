<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AttendanceRequestKind: string
{
    use TranslatableEnum;

    case Wfh = 'wfh';
    case OnDuty = 'on_duty';

    public static function translationKey(): string
    {
        return 'attendance.enums.request_kind';
    }
}
