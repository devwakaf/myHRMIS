<?php

declare(strict_types=1);

namespace App\Domain\Core\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum QualificationLevel: string
{
    use TranslatableEnum;

    case Spm = 'spm';
    case Stpm = 'stpm';
    case Certificate = 'certificate';
    case Diploma = 'diploma';
    case Degree = 'degree';
    case Masters = 'masters';
    case Doctorate = 'doctorate';
    case Professional = 'professional';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'core.enums.qualification_level';
    }
}
