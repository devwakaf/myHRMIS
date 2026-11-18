<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum CreditScheduleStatus: string
{
    use TranslatableEnum;

    case Pending = 'pending';
    case Posted = 'posted';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public static function translationKey(): string
    {
        return 'leave.enums.credit_schedule_status';
    }
}
