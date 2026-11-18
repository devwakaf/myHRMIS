<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum MaritalStatus: string
{
    use TranslatableEnum;

    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Widowed = 'widowed';

    public static function translationKey(): string
    {
        return 'core.enums.marital_status';
    }
}
