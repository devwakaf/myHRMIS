<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

use App\Domain\Shared\Money\Num;

/**
 * Year-to-date totals of an employee (spec-04 §2.9): Y (normal / additional
 * remuneration), K (employee EPF), X (PCB net, excl. CP38), Z (zakat), LP (other
 * deductions), plus the figures EA and listings need.
 */
final class TaxYearTotals
{
    /**
     * @param  array<string, string>  $eaBoxes
     * @param  array<string, string>  $taxExemptUsed
     */
    public function __construct(
        public readonly int $taxYear,
        public readonly ?string $throughPeriod = null,
        public readonly string $yNormal = '0',
        public readonly string $yAdditional = '0',
        public readonly string $k = '0',
        public readonly string $x = '0',
        public readonly string $z = '0',
        public readonly string $lp = '0',
        public readonly string $cp38 = '0',
        public readonly string $socsoEmployee = '0',
        public readonly string $eisEmployee = '0',
        public readonly string $skbbkEmployee = '0',
        public readonly string $epfEmployer = '0',
        public readonly array $eaBoxes = [],
        public readonly array $taxExemptUsed = [],
    ) {}

    public function y(): string
    {
        return Num::add($this->yNormal, $this->yAdditional);
    }

    /**
     * @return array<string, string|array<string, string>|int|null>
     */
    public function toArray(): array
    {
        return [
            'tax_year' => $this->taxYear, 'through_period' => $this->throughPeriod,
            'y_normal' => Num::money($this->yNormal), 'y_additional' => Num::money($this->yAdditional), 'k' => Num::money($this->k),
            'x' => Num::money($this->x), 'z' => Num::money($this->z), 'lp' => Num::money($this->lp), 'cp38_total' => Num::money($this->cp38),
            'socso_employee' => Num::money($this->socsoEmployee), 'eis_employee' => Num::money($this->eisEmployee),
            'skbbk_employee' => Num::money($this->skbbkEmployee), 'epf_employer' => Num::money($this->epfEmployer),
            'ea_boxes' => $this->eaBoxes, 'tax_exempt_used' => $this->taxExemptUsed,
        ];
    }
}
