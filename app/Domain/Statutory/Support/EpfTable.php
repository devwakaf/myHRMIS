<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Statutory\Enums\AgeSwitchRule;
use App\Domain\Statutory\Enums\CitizenshipGroup;
use App\Domain\Statutory\Enums\EpfPart;

/** KWSP rate set loaded into memory (rules, params, Third Schedule bands per part). */
final class EpfTable
{
    /**
     * @param  list<EpfRule>  $rules
     * @param  array<string, list<Band>>  $bands  part => bands sorted by wage_from
     */
    public function __construct(
        public readonly int $rateSetId,
        public readonly string $code,
        public readonly int $minAge,
        public readonly int $maxAge,
        public readonly AgeSwitchRule $ageSwitchRule,
        public readonly int $workPassStopMonths,
        public readonly array $rules,
        public readonly array $bands,
    ) {}

    public function rule(CitizenshipGroup $group, int $age): ?EpfRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($group, $age)) {
                return $rule;
            }
        }

        return null;
    }

    public function band(EpfPart $part, string $wage): ?Band
    {
        return Band::find($this->bands[$part->value] ?? [], $wage);
    }
}
