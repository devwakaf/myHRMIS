<?php

declare(strict_types=1);

namespace App\Domain\Statutory\Support;

use App\Domain\Statutory\Enums\EpfPart;

/** KWSP outcome for one slip (amounts "0.00" strings). */
final class EpfResult
{
    /**
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $trace
     */
    public function __construct(
        public readonly EpfPart $part,
        public readonly string $method,
        public readonly string $wage,
        public readonly string $wageExclBonus,
        public readonly string $employee,
        public readonly string $employer,
        public readonly string $employeeNormal,
        public readonly ?int $rateSetId,
        public readonly array $warnings = [],
        public readonly array $trace = [],
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function none(string $wage, string $wageExclBonus, ?int $rateSetId, array $warnings = []): self
    {
        return new self(EpfPart::None, 'none', $wage, $wageExclBonus, '0.00', '0.00', '0.00', $rateSetId, $warnings);
    }
}
