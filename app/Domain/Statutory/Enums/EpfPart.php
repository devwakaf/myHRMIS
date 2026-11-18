<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EpfPart: string
{
    use TranslatableEnum;

    case A = 'A';
    case C = 'C';
    case E = 'E';
    case F = 'F';
    case None = 'none';

    public static function translationKey(): string
    {
        return 'statutory.enums.epf_part';
    }
}
