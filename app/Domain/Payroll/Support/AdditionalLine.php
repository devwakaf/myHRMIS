<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;

/** An Additional Salary that applies to the slip's month (spec-04 §4.4). */
final class AdditionalLine
{
    public function __construct(
        public readonly ?int $additionalSalaryId,
        public readonly int $componentId,
        public readonly string $amount,
        public readonly AdditionalMode $mode,
        public readonly bool $prorate,
        public readonly AdditionalSource $source,
        public readonly ?string $quantity = null,
        public readonly ?string $note = null,
    ) {}
}
