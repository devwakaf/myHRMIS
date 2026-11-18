<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum StatutoryKey: string
{
    use TranslatableEnum;

    case EpfEmployee = 'epf_employee';
    case EpfEmployer = 'epf_employer';
    case SocsoEmployee = 'socso_employee';
    case SocsoEmployer = 'socso_employer';
    case SkbbkEmployee = 'skbbk_employee';
    case EisEmployee = 'eis_employee';
    case EisEmployer = 'eis_employer';
    case Pcb = 'pcb';
    case Zakat = 'zakat';
    case Cp38 = 'cp38';
    case HrdcLevy = 'hrdc_levy';
    case LwpDeduction = 'lwp_deduction';

    public static function translationKey(): string
    {
        return 'payroll.enums.statutory_key';
    }
}
