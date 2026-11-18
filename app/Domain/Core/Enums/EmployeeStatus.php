<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EmployeeStatus: string
{
    use TranslatableEnum;

    case Active = 'active';
    case Suspended = 'suspended';
    case Left = 'left';

    public static function translationKey(): string
    {
        return 'core.enums.employee_status';
    }
}
