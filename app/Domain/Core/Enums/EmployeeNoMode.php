<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EmployeeNoMode: string
{
    use TranslatableEnum;

    case Auto = 'auto';
    case Manual = 'manual';

    public static function translationKey(): string
    {
        return 'core.enums.employee_no_mode';
    }
}
