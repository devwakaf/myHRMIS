<?php

declare(strict_types=1);

namespace App\Domain\Shared\Lifecycle;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ApprovalStatus: string implements LifecycleStatus
{
    use TranslatableEnum;

    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public static function translationKey(): string
    {
        return 'common.approval_status';
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending],
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Cancelled],
            self::Rejected, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Cancelled;
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
