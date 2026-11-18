<?php

declare(strict_types=1);

namespace App\Domain\Leave\Notifications;

/** To the Kakitangan: unused leave on a lot expires soon (spec-02 §4.3). */
final class LeaveExpiringSoon extends LeaveNotice
{
    public function __construct(
        public readonly string $typeName,
        public readonly string $days,
        public readonly string $expiresOn,
    ) {}

    public function kind(): string
    {
        return 'expiring_soon';
    }

    public function params(): array
    {
        return ['type' => $this->typeName, 'days' => $this->days, 'date' => $this->expiresOn];
    }
}
