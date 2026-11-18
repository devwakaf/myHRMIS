<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Statutory\Support\EisTable;
use App\Domain\Statutory\Support\EpfTable;
use App\Domain\Statutory\Support\HrdcTable;
use App\Domain\Statutory\Support\MtdTable;
use App\Domain\Statutory\Support\ReliefTable;
use App\Domain\Statutory\Support\SocsoTable;
use App\Domain\Statutory\Support\StatutoryPerson;

/**
 * Everything the statutory engine needs for one slip, already loaded (no DB):
 * the effective tables (null when the scheme is not needed), the person, tax
 * profile values, YTD and this month's declarations.
 */
final class StatutoryInputs
{
    public function __construct(
        public readonly StatutoryPerson $person,
        public readonly ?EpfTable $epf,
        public readonly ?SocsoTable $socso,
        public readonly ?EisTable $eis,
        public readonly ?MtdTable $mtd,
        public readonly ?HrdcTable $hrdc,
        public readonly ?ReliefTable $reliefs,
        public readonly TaxYearTotals $ytd,
        public readonly int $pcbCategory = 1,
        public readonly bool $resident = true,
        public readonly string $mtdScheme = 'normal',
        public readonly bool $disabledSelf = false,
        public readonly bool $spouseDisabled = false,
        public readonly bool $claimsChildRelief = false,
        public readonly int $childrenBasic = 0,
        public readonly int $childrenHigherEd = 0,
        public readonly int $childrenDisabled = 0,
        public readonly int $childrenDisabledHigherEd = 0,
        public readonly string $tp1ThisMonth = '0',
        public readonly string $zakatExternal = '0',
        public readonly ?string $pcbOverride = null,
        public readonly ?string $epfEmployeeRateOverride = null,
        public readonly ?string $epfEmployerRateOverride = null,
        public readonly bool $hrdcRegistered = false,
        public readonly ?string $hrdcRate = null,
        public readonly int $headcount = 0,
        public readonly string $y2Basis = 'current_month',
    ) {}
}
