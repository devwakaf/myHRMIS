<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use BcMath\Number;
use JsonSerializable;
use Stringable;

/**
 * Immutable MYR amount. Stored values have scale 2; intermediate results keep
 * scale 6 until explicitly rounded through Rounding.
 */
final class Money implements JsonSerializable, Stringable
{
    public const STORED_SCALE = 2;

    public const WORKING_SCALE = 6;

    private function __construct(private readonly Number $amount) {}

    public static function of(Number|string|int $amount): self
    {
        $number = $amount instanceof Number ? $amount : new Number(is_int($amount) ? $amount : self::normalise($amount));

        return new self($number);
    }

    public static function zero(): self
    {
        return new self(new Number('0'));
    }

    public function amount(): Number
    {
        return $this->amount;
    }

    public function plus(self|Number|string|int $other): self
    {
        return new self($this->amount->add(self::raw($other), self::WORKING_SCALE));
    }

    public function minus(self|Number|string|int $other): self
    {
        return new self($this->amount->sub(self::raw($other), self::WORKING_SCALE));
    }

    public function times(Number|string|int $factor): self
    {
        return new self($this->amount->mul(self::raw($factor), self::WORKING_SCALE));
    }

    /** $rate is a percentage: "11" or "11.0000" means 11%. */
    public function percent(Number|string|int $rate): self
    {
        return new self($this->amount->mul(self::raw($rate), self::WORKING_SCALE)->div(100, self::WORKING_SCALE));
    }

    public function dividedBy(Number|string|int $divisor): self
    {
        return new self($this->amount->div(self::raw($divisor), self::WORKING_SCALE));
    }

    /** Round half-up to sen. */
    public function rounded(): self
    {
        return new self(Rounding::halfUp($this->amount, self::STORED_SCALE));
    }

    public function isZero(): bool
    {
        return $this->amount->compare(0) === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount->compare(0) < 0;
    }

    public function compare(self|Number|string|int $other): int
    {
        return $this->amount->compare(self::raw($other));
    }

    public function equals(self|Number|string|int $other): bool
    {
        return $this->compare($other) === 0;
    }

    /** "1234.56" — always two decimals (rounded half-up). */
    public function toDecimalString(): string
    {
        return (string) Rounding::halfUp($this->amount, self::STORED_SCALE);
    }

    /** "RM 1,234.56" / "-RM 1,234.56" (same in both locales). */
    public function format(): string
    {
        return Format::money($this->toDecimalString());
    }

    /**
     * @return array{amount: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->toDecimalString(), 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    private static function raw(self|Number|string|int $value): Number
    {
        if ($value instanceof self) {
            return $value->amount;
        }

        if ($value instanceof Number) {
            return $value;
        }

        return new Number(is_int($value) ? $value : self::normalise($value));
    }

    private static function normalise(string $value): string
    {
        $value = str_replace([',', ' ', 'RM'], '', trim($value));

        return $value === '' || $value === '-' ? '0' : $value;
    }
}
