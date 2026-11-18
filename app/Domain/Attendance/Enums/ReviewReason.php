<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ReviewReason: string
{
    use TranslatableEnum;

    case SinglePunch = 'single_punch';
    case OffShift = 'off_shift';
    case AmbiguousShift = 'ambiguous_shift';
    case ExistingAttendance = 'existing_attendance';
    case WorkedOnLeave = 'worked_on_leave';
    case InactiveEmployee = 'inactive_employee';
    case LockedPeriod = 'locked_period';
    case OutsideGeofence = 'outside_geofence';
    case ProcessingError = 'processing_error';

    public static function translationKey(): string
    {
        return 'attendance.enums.review_reason';
    }
}
