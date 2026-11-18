<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ShiftAssignmentSource: string
{
    use TranslatableEnum;

    case Manual = 'manual';
    case Bulk = 'bulk';
    case Roster = 'roster';
    case Request = 'request';

    public static function translationKey(): string
    {
        return 'attendance.enums.assignment_source';
    }
}
