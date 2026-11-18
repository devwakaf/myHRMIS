<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Shared\Money\Num;

/**
 * Day counters of one slip (spec-04 §4.3, deduction model). Day values are
 * decimal strings at scale 3; the fraction keeps scale 10.
 */
final class PaymentDays
{
    public function __construct(
        public readonly string $calendarDays,
        public readonly ?string $workingDays,
        public readonly string $basis,
        public readonly string $divisor,
        public readonly string $outsideDays,
        public readonly string $lwpDays,
        public readonly string $absentDays,
        public readonly string $unmarkedDays,
        public readonly bool $unmarkedCounted,
        public readonly bool $isNewJoiner = false,
        public readonly bool $isLeaver = false,
    ) {}

    public function unpaidTotal(): string
    {
        return Num::add($this->outsideDays, $this->lwpDays, $this->absentDays, $this->unmarkedCounted ? $this->unmarkedDays : '0');
    }

    /** divisor − unpaid, clamped to [0, divisor]. */
    public function paidNumerator(): string
    {
        return Num::clamp(Num::sub($this->divisor, $this->unpaidTotal()), '0', $this->divisor);
    }

    /** divisor − days outside the employment span (the "span" amount shown in separate_deduction mode). */
    public function spanNumerator(): string
    {
        return Num::clamp(Num::sub($this->divisor, $this->outsideDays), '0', $this->divisor);
    }

    public function fraction(): string
    {
        return Num::isPositive($this->divisor) ? Num::div($this->paidNumerator(), $this->divisor) : '0';
    }

    public function paymentDays(): string
    {
        return Num::fixed(Num::mul($this->divisor, $this->fraction()), 3);
    }

    /** full × (divisor − unpaid) ÷ divisor, multiply first (unrounded). */
    public function prorate(string $full): string
    {
        return Num::isPositive($this->divisor) ? Num::div(Num::mul($full, $this->paidNumerator()), $this->divisor) : '0';
    }

    public function prorateSpan(string $full): string
    {
        return Num::isPositive($this->divisor) ? Num::div(Num::mul($full, $this->spanNumerator()), $this->divisor) : '0';
    }

    /** Full-month variant for the full-cycle bag and dry runs. */
    public static function fullMonth(string $calendarDays, ?string $workingDays, string $basis, string $divisor): self
    {
        return new self($calendarDays, $workingDays, $basis, $divisor, '0', '0', '0', '0', false);
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'calendar_days' => $this->calendarDays, 'working_days' => $this->workingDays, 'basis' => $this->basis,
            'divisor' => $this->divisor, 'outside_employment_days' => $this->outsideDays, 'lwp_days' => $this->lwpDays,
            'absent_days' => $this->absentDays, 'unmarked_days' => $this->unmarkedDays, 'unmarked_counted' => $this->unmarkedCounted,
            'unpaid_days_total' => Num::fixed($this->unpaidTotal(), 3), 'payment_fraction' => Num::fixed($this->fraction(), 10),
            'payment_days' => $this->paymentDays(),
        ];
    }
}
