<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum OtherHalfStatus: string
{
    use TranslatableEnum;

    case Pending = 'pending';
    case Present = 'present';
    case Absent = 'absent';
    case Wfh = 'wfh';
    case OnDuty = 'on_duty';

    public static function translationKey(): string
    {
        return 'attendance.enums.other_half_status';
    }
}
