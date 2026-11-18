<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * DECIMAL(15,2) column <-> Money.
 *
 * @implements CastsAttributes<Money|null, Money|string|int|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::of((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Money) {
            return $value->toDecimalString();
        }

        if (is_string($value) || is_int($value)) {
            return Money::of($value)->toDecimalString();
        }

        throw new InvalidArgumentException("Money attribute [{$key}] must be a Money, string or int; floats are not allowed.");
    }
}
