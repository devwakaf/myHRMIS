<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum LocationLinkType: string
{
    use TranslatableEnum;

    case Branch = 'branch';
    case Employee = 'employee';
    case ShiftAssignment = 'shift_assignment';
    case RosterAssignment = 'roster_assignment';

    public static function translationKey(): string
    {
        return 'attendance.enums.location_link_type';
    }
}
