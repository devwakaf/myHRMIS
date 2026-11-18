<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AssignableType: string
{
    use TranslatableEnum;

    case Organisation = 'organisation';
    case Branch = 'branch';
    case Employee = 'employee';

    public static function translationKey(): string
    {
        return 'core.enums.assignable_type';
    }
}
