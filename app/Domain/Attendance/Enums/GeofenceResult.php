<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum GeofenceResult: string
{
    use TranslatableEnum;

    case Inside = 'inside';
    case OutsideAccepted = 'outside_accepted';
    case NotRequired = 'not_required';
    case Exempt = 'exempt';
    case OnDutyBypass = 'on_duty_bypass';
    case NotApplicable = 'not_applicable';

    public static function translationKey(): string
    {
        return 'attendance.enums.geofence_result';
    }
}
