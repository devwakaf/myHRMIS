<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum RequestDayOutcome: string
{
    use TranslatableEnum;

    case Created = 'created';
    case ReplacedAbsent = 'replaced_absent';
    case CompletedHalfDay = 'completed_half_day';
    case SkippedRestOrHoliday = 'skipped_rest_or_holiday';
    case SkippedLeave = 'skipped_leave';
    case SkippedLocked = 'skipped_locked';
    case SkippedWorked = 'skipped_worked';

    public static function translationKey(): string
    {
        return 'attendance.enums.request_day_outcome';
    }
}
