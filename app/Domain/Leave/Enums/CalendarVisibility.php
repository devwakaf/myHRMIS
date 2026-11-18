<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CalendarVisibility: string
{
    use TranslatableEnum;

    case Off = 'off';
    case DepartmentNamesOnly = 'department_names_only';

    public static function translationKey(): string
    {
        return 'leave.enums.calendar_visibility';
    }
}
