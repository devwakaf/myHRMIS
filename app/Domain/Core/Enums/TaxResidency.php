<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum TaxResidency: string
{
    use TranslatableEnum;

    case Resident = 'resident';
    case NonResident = 'non_resident';

    public static function translationKey(): string
    {
        return 'core.enums.tax_residency';
    }
}
