<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum BloodType: string
{
    use TranslatableEnum;

    case APos = 'A+';
    case ANeg = 'A-';
    case BPos = 'B+';
    case BNeg = 'B-';
    case AbPos = 'AB+';
    case AbNeg = 'AB-';
    case OPos = 'O+';
    case ONeg = 'O-';

    public static function translationKey(): string
    {
        return 'core.enums.blood_type';
    }
}
