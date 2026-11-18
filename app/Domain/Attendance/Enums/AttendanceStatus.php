<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AttendanceStatus: string
{
    use TranslatableEnum;

    case Present = 'present';
    case Absent = 'absent';
    case HalfDay = 'half_day';
    case OnLeave = 'on_leave';
    case Wfh = 'wfh';
    case OnDuty = 'on_duty';

    public static function translationKey(): string
    {
        return 'attendance.enums.attendance_status';
    }
}
