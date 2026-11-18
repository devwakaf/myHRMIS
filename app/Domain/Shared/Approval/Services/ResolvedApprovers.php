<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Services;

use App\Domain\Shared\Approval\Enums\AssigneeOrigin;

/**
 * Outcome of approver resolution for one step.
 */
final class ResolvedApprovers
{
    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $candidates  applicant_chooses candidate list (empty otherwise)
     */
    public function __construct(
        public readonly array $userIds,
        public readonly AssigneeOrigin $origin = AssigneeOrigin::Rule,
        public readonly array $candidates = [],
        public readonly ?int $defaultChoice = null,
        public readonly bool $choiceRequired = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->userIds === [];
    }
}
