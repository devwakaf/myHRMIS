<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum Decision: string
{
    use TranslatableEnum;

    case Approve = 'approve';
    case Reject = 'reject';

    public static function translationKey(): string
    {
        return 'approval.decisions';
    }
}
