<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

/** HRD Corp levy rules (MEDIUM / UNVERIFIED-STAT). */
final class HrdcTable
{
    /**
     * @param  list<array{min_headcount: int, max_headcount: int|null, rate: string, mandatory: bool, base_citizenships: list<string>, rounding_mode: string}>  $rules
     */
    public function __construct(
        public readonly int $rateSetId,
        public readonly array $rules,
    ) {}

    /**
     * @return array{min_headcount: int, max_headcount: int|null, rate: string, mandatory: bool, base_citizenships: list<string>, rounding_mode: string}|null
     */
    public function ruleFor(int $headcount): ?array
    {
        foreach ($this->rules as $rule) {
            if ($headcount >= $rule['min_headcount'] && ($rule['max_headcount'] === null || $headcount <= $rule['max_headcount'])) {
                return $rule;
            }
        }

        return $this->rules[0] ?? null;
    }
}
