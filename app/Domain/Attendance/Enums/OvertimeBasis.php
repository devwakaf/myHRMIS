<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum OvertimeBasis: string
{
    use TranslatableEnum;

    case Statutory = 'statutory';
    case Contractual = 'contractual';

    public static function translationKey(): string
    {
        return 'attendance.enums.overtime_basis';
    }
}
