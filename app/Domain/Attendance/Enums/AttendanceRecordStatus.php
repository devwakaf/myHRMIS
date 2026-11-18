<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Enums;

use App\Domain\Shared\Lifecycle\LifecycleStatus;
use App\Domain\Shared\Support\Enums\TranslatableEnum;

/**
 * Rekod Kehadiran lifecycle (spec-03 §3.11, A5.1 extension): Sah → Digantikan
 * (by leave / request / HR edit) and back when the superseder is cancelled;
 * Sah | Digantikan → Dibatalkan.
 */
enum AttendanceRecordStatus: string implements LifecycleStatus
{
    use TranslatableEnum;

    case Submitted = 'submitted';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public static function translationKey(): string
    {
        return 'attendance.enums.record_status';
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::Superseded, self::Cancelled],
            self::Superseded => [self::Submitted, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    public function isDraft(): bool
    {
        return false;
    }
}
