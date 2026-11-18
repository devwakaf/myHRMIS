<?php

declare(strict_types=1);

namespace App\Domain\Leave\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum AttachmentKind: string
{
    use TranslatableEnum;

    case Mc = 'mc';
    case HospitalLetter = 'hospital_letter';
    case Other = 'other';

    public static function translationKey(): string
    {
        return 'leave.enums.attachment_kind';
    }
}
