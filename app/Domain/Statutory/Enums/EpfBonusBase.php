<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EpfBonusBase: string
{
    use TranslatableEnum;

    case BandUpper = 'band_upper';
    case ActualWage = 'actual_wage';

    public static function translationKey(): string
    {
        return 'statutory.enums.epf_bonus_base';
    }
}
