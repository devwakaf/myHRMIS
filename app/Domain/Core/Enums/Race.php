<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum Race: string
{
    use TranslatableEnum;

    case Malay = 'malay';
    case Chinese = 'chinese';
    case Indian = 'indian';
    case BumiputeraSabah = 'bumiputera_sabah';
    case BumiputeraSarawak = 'bumiputera_sarawak';
    case OrangAsli = 'orang_asli';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'core.enums.race';
    }
}
