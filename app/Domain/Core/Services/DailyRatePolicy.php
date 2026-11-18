<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\DailyRateBasis;
use App\Domain\Core\Models\Organisation;

/**
 * Kadar gaji harian per Organisasi (B1.1, USER DECISION): basis + divisor.
 * Applies to unpaid-leave deductions and pro-rating only. Akta Kerja OT /
 * rest-day / public-holiday ORP always uses ÷26 (see ordinaryRateDivisor()).
 */
final class DailyRatePolicy
{
    public function __construct(
        public readonly DailyRateBasis $basis,
        public readonly string $divisor,
    ) {}

    public static function for(Organisation $organisation): self
    {
        return new self($organisation->daily_rate_basis, (string) $organisation->daily_rate_divisor);
    }

    /** Akta Kerja ordinary rate of pay divisor (monthly wage ÷ 26) [stat §6.2]. */
    public static function ordinaryRateDivisor(): string
    {
        return '26';
    }

    /**
     * @return array{basis: string, divisor: string}
     */
    public function toArray(): array
    {
        return ['basis' => $this->basis->value, 'divisor' => $this->divisor];
    }
}
