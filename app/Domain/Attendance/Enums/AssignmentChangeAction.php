<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AssignmentChangeAction: string
{
    use TranslatableEnum;

    case Created = 'created';
    case Trimmed = 'trimmed';
    case SplitTailCreated = 'split_tail_created';
    case Cancelled = 'cancelled';
    case RestDayCreated = 'rest_day_created';
    case RestDayRemoved = 'rest_day_removed';

    public static function translationKey(): string
    {
        return 'attendance.enums.change_action';
    }
}
