<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum FileScheme: string
{
    use TranslatableEnum;

    case Kwsp = 'kwsp';
    case Perkeso = 'perkeso';
    case Cp39 = 'cp39';
    case Ea = 'ea';
    case Cp8d = 'cp8d';
    case Pcb2ii = 'pcb2ii';

    public static function translationKey(): string
    {
        return 'statutory.enums.file_scheme';
    }
}
