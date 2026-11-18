<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum GenerationStatus: string
{
    use TranslatableEnum;

    case Pending = 'pending';
    case Success = 'success';
    case Warning = 'warning';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public static function translationKey(): string
    {
        return 'payroll.enums.generation_status';
    }
}
