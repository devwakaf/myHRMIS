<?php

declare(strict_types=1);

namespace App\Domain\Leave\Notifications;

/** To HR: one or more scheduled credits failed and can be retried (spec-02 §3.8). */
final class LeaveCreditFailed extends LeaveNotice
{
    public function __construct(public readonly int $count) {}

    public function kind(): string
    {
        return 'credit_failed';
    }

    public function params(): array
    {
        return ['count' => (string) $this->count];
    }
}
