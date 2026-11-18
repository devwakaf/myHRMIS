<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum EaFormStatus: string
{
    use TranslatableEnum;

    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';

    public static function translationKey(): string
    {
        return 'payroll.enums.ea_form_status';
    }
}
