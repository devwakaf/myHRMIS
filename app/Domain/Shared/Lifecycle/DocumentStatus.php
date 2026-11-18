<?php

declare(strict_types=1);

namespace App\Domain\Shared\Lifecycle;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum DocumentStatus: string implements LifecycleStatus
{
    use TranslatableEnum;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';

    public static function translationKey(): string
    {
        return 'common.document_status';
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
