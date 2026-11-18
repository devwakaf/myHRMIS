<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AssignmentChangeCause: string
{
    use TranslatableEnum;

    case ShiftRequest = 'shift_request';
    case RosterEdit = 'roster_edit';
    case BulkReplace = 'bulk_replace';
    case Separation = 'separation';

    public static function translationKey(): string
    {
        return 'attendance.enums.change_cause';
    }
}
