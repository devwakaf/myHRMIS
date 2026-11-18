<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Lifecycle\LifecycleStatus;
use App\Domain\Shared\Support\Enums\TranslatableEnum;

/**
 * Payroll Run lifecycle (spec-04 §2.1): Draf → Slip Dijana → Dihantar (dikunci) → Dibayar.
 * Queue progress is a separate ProcessingState.
 */
enum PayrollRunStatus: string implements LifecycleStatus
{
    use TranslatableEnum;

    case Draft = 'draft';
    case SlipsGenerated = 'slips_generated';
    case Submitted = 'submitted';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public static function translationKey(): string
    {
        return 'payroll.enums.run_status';
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::SlipsGenerated, self::Cancelled],
            self::SlipsGenerated => [self::Draft, self::Submitted, self::Cancelled],
            self::Submitted => [self::Paid, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Paid || $this === self::Cancelled;
    }

    /** Runs stay editable (regenerate, exclude) until submitted. */
    public function isDraft(): bool
    {
        return $this === self::Draft || $this === self::SlipsGenerated;
    }

    public function isLocked(): bool
    {
        return $this === self::Submitted || $this === self::Paid;
    }
}
