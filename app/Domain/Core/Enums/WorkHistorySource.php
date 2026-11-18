<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum WorkHistorySource: string
{
    use TranslatableEnum;

    case Hire = 'hire';
    case Movement = 'movement';
    case Correction = 'correction';
    case Rehire = 'rehire';

    public static function translationKey(): string
    {
        return 'core.enums.work_history_source';
    }
}
