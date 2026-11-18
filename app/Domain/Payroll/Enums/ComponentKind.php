<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Enums;

use App\Domain\Shared\Support\Enums\TranslatableEnum;

enum ComponentKind: string
{
    use TranslatableEnum;

    case Earning = 'earning';
    case Deduction = 'deduction';
    case EmployerContribution = 'employer_contribution';
    case Statistical = 'statistical';

    public static function translationKey(): string
    {
        return 'payroll.enums.component_kind';
    }

    /** Evaluation tie-break order (spec-04 §3.5): earning → statistical → deduction → employer contribution. */
    public function evaluationOrder(): int
    {
        return match ($this) {
            self::Earning => 1,
            self::Statistical => 2,
            self::Deduction => 3,
            self::EmployerContribution => 4,
        };
    }

    /** Display order on slips and registers. */
    public function displayOrder(): int
    {
        return match ($this) {
            self::Earning => 1,
            self::Deduction => 2,
            self::EmployerContribution => 3,
            self::Statistical => 4,
        };
    }
}
