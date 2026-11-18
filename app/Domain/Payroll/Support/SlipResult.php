<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Statutory\Support\EisResult;
use App\Domain\Statutory\Support\EpfResult;
use App\Domain\Statutory\Support\MtdResult;
use App\Domain\Statutory\Support\SocsoResult;

/** Output of the SlipCalculator (spec-04 §4.7–§4.9). */
final class SlipResult
{
    /**
     * @param  list<LineResult>  $lines
     * @param  array{applies: bool, wage: string, rate: string, levy: string, rate_set_id: int|null}|null  $hrdc
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $trace
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?EpfResult $epf,
        public readonly ?SocsoResult $socso,
        public readonly ?EisResult $eis,
        public readonly ?MtdResult $mtd,
        public readonly ?array $hrdc,
        public readonly string $zakatPayroll,
        public readonly string $zakatExternal,
        public readonly string $cp38,
        public readonly string $pcbLine,
        public readonly string $pcbMethod,
        public readonly string $gross,
        public readonly string $deductions,
        public readonly string $net,
        public readonly string $netRoundingAdjustment,
        public readonly string $employerTotal,
        public readonly array $warnings,
        public readonly array $trace,
        public readonly bool $hasErrors,
    ) {}

    public function line(string $code): ?LineResult
    {
        foreach ($this->lines as $line) {
            if ($line->component->code === $code) {
                return $line;
            }
        }

        return null;
    }

    public function amount(string $code): string
    {
        return $this->line($code)->amount ?? '0.00';
    }

    /**
     * @return list<int>
     */
    public function rateSetIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->epf?->rateSetId, $this->socso?->rateSetId, $this->eis?->rateSetId, $this->mtd?->rateSetId, $this->hrdc['rate_set_id'] ?? null,
        ], fn ($id) => $id !== null)));
    }
}
