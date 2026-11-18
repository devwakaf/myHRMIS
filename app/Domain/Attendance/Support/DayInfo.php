<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\DayType;

/**
 * Result of DayTypeResolver (spec-03 §4.2). `source`: holiday_list, roster, default.
 */
final class DayInfo
{
    public function __construct(
        public readonly DayType $dayType,
        public readonly bool $isHalfDayOff = false,
        public readonly ?string $label = null,
        public readonly string $source = 'default',
    ) {}

    public function isNormal(): bool
    {
        return $this->dayType === DayType::Normal;
    }
}
