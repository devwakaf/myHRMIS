<?php

declare(strict_types=1);

namespace App\Domain\Shared\Lifecycle;

use App\Domain\Shared\Support\DomainError;

final class DocumentLocked extends DomainError
{
    /**
     * @param  list<string>  $attributes
     */
    public static function forAttributes(array $attributes): self
    {
        return new self('common.errors.document_locked', ['fields' => implode(', ', $attributes)]);
    }

    public static function notDeletable(): self
    {
        return new self('common.errors.document_not_deletable');
    }
}
