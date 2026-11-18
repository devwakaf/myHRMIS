<?php

declare(strict_types=1);

namespace App\Domain\Shared\Lifecycle;

use App\Domain\Shared\Support\DomainError;

final class InvalidTransition extends DomainError
{
    public static function between(LifecycleStatus $from, LifecycleStatus $to): self
    {
        return new self('common.errors.invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
    }
}
