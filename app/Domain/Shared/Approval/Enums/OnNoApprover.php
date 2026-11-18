<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum OnNoApprover: string
{
    use TranslatableEnum;

    case Fail = 'fail';
    case Skip = 'skip';
    case HrQueue = 'hr_queue';

    public static function translationKey(): string
    {
        return 'approval.on_no_approver';
    }
}
