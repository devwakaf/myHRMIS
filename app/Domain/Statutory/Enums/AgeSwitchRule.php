<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AgeSwitchRule: string
{
    use TranslatableEnum;

    case MonthAfterBirthday = 'month_after_birthday';
    case BirthdayMonth = 'birthday_month';

    public static function translationKey(): string
    {
        return 'statutory.enums.age_switch_rule';
    }
}
