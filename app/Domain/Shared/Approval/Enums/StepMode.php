<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum StepMode: string
{
    use TranslatableEnum;

    case AnyOne = 'any_one';
    case All = 'all';

    public static function translationKey(): string
    {
        return 'approval.modes';
    }
}
