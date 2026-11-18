<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\MaritalStatus;

/**
 * B3.4: single → 1; married + spouse not working → 2; married + spouse working,
 * divorced, widowed → 3. "Single with adopted child" → 3 needs a manual override.
 */
final class PcbCategorySuggester
{
    public function suggest(MaritalStatus $maritalStatus, ?bool $spouseWorking): int
    {
        return match ($maritalStatus) {
            MaritalStatus::Single => 1,
            MaritalStatus::Married => $spouseWorking === false ? 2 : 3,
            MaritalStatus::Divorced, MaritalStatus::Widowed => 3,
        };
    }
}
