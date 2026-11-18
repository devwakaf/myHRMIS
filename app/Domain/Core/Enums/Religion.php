<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum Religion: string
{
    use TranslatableEnum;

    case Islam = 'islam';
    case Buddhism = 'buddhism';
    case Christianity = 'christianity';
    case Hinduism = 'hinduism';
    case Sikhism = 'sikhism';
    case Taoism = 'taoism';
    case Other = 'other';
    case None = 'none';

    public static function translationKey(): string
    {
        return 'core.enums.religion';
    }
}
