<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AssignmentReason: string
{
    use TranslatableEnum;

    case NewHire = 'new_hire';
    case Increment = 'increment';
    case Promotion = 'promotion';
    case Correction = 'correction';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'payroll.enums.assignment_reason';
    }
}
