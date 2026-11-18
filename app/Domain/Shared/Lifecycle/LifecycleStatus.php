<?php

declare(strict_types=1);

namespace App\Domain\Shared\Lifecycle;

use BackedEnum;

interface LifecycleStatus extends BackedEnum
{
    /**
     * Statuses this status may move to.
     *
     * @return list<static>
     */
    public function allowedTransitions(): array;

    public function isTerminal(): bool;

    public function isDraft(): bool;

    public function label(): string;
}
