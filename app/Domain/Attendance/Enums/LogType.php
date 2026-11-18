<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum LogType: string
{
    use TranslatableEnum;

    case In = 'in';
    case Out = 'out';

    public static function translationKey(): string
    {
        return 'attendance.enums.log_type';
    }
}
