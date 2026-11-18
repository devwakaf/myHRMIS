<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Illuminate\Validation\ValidationException;

final class DomainValidation
{
    /**
     * @param  array<string, string|list<string>>  $messages
     */
    public static function withMessages(array $messages): ValidationException
    {
        return ValidationException::withMessages($messages);
    }

    /**
     * Throw if any messages were collected.
     *
     * @param  array<string, string|list<string>>  $messages
     */
    public static function throwIf(array $messages): void
    {
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
