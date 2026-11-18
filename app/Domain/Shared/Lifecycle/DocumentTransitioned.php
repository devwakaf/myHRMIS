<?php

declare(strict_types=1);

namespace App\Domain\Shared\Lifecycle;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final class DocumentTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Model $document,
        public readonly LifecycleStatus $from,
        public readonly LifecycleStatus $to,
        public readonly ?int $actorId,
        public readonly ?string $reason,
    ) {}
}
