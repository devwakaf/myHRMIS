<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ClaimEligibility: string
{
    use TranslatableEnum;

    case EligibleWage = 'eligible_wage';
    case EligibleManualLabour = 'eligible_manual_labour';
    case EligibleOverride = 'eligible_override';
    case NotEligible = 'not_eligible';

    public static function translationKey(): string
    {
        return 'attendance.enums.claim_eligibility';
    }
}
