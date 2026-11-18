<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum LineSource: string
{
    use TranslatableEnum;

    case Structure = 'structure';
    case AssignmentOverride = 'assignment_override';
    case Additional = 'additional';
    case Statutory = 'statutory';
    case Overtime = 'overtime';
    case LeaveEncashment = 'leave_encashment';
    case LwpDeduction = 'lwp_deduction';

    public static function translationKey(): string
    {
        return 'payroll.enums.line_source';
    }
}
