<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Statutory\Enums\AgeSwitchRule;
use Carbon\CarbonImmutable;

/**
 * Age used for a scheme in a month (spec-04 §4.6; the switch month is UNVERIFIED-STAT).
 * month_after_birthday: age on the day before the period starts, so a new rate applies
 * from the month after the birthday month. birthday_month: age at period end.
 */
final class StatutoryAge
{
    public static function forPeriod(CarbonImmutable $dateOfBirth, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, AgeSwitchRule $rule): int
    {
        $asAt = $rule === AgeSwitchRule::BirthdayMonth ? $periodEnd : $periodStart->subDay();

        return max(0, (int) $dateOfBirth->diffInYears($asAt));
    }
}
