<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum OutsideGeofenceAction: string
{
    use TranslatableEnum;

    case Reject = 'reject';
    case AcceptAndReview = 'accept_and_review';

    public static function translationKey(): string
    {
        return 'attendance.enums.outside_geofence_action';
    }
}
