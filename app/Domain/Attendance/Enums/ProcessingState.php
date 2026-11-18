<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ProcessingState: string
{
    use TranslatableEnum;

    case Pending = 'pending';
    case Processed = 'processed';
    case NeedsReview = 'needs_review';
    case Ignored = 'ignored';

    public static function translationKey(): string
    {
        return 'attendance.enums.processing_state';
    }
}
