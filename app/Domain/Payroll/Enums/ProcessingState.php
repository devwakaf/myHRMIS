<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ProcessingState: string
{
    use TranslatableEnum;

    case Idle = 'idle';
    case Queued = 'queued';
    case Running = 'running';
    case Failed = 'failed';

    public static function translationKey(): string
    {
        return 'payroll.enums.processing_state';
    }
}
