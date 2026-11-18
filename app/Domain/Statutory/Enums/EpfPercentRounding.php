<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EpfPercentRounding: string
{
    use TranslatableEnum;

    case CeilEachShare = 'ceil_each_share';
    case CeilTotalThenSplit = 'ceil_total_then_split';

    public static function translationKey(): string
    {
        return 'statutory.enums.epf_percent_rounding';
    }
}
