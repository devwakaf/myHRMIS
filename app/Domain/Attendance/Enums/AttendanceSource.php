<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AttendanceSource: string
{
    use TranslatableEnum;

    case Auto = 'auto';
    case Manual = 'manual';
    case Bulk = 'bulk';
    case Request = 'request';
    case Leave = 'leave';
    case Import = 'import';

    public static function translationKey(): string
    {
        return 'attendance.enums.attendance_source';
    }
}
