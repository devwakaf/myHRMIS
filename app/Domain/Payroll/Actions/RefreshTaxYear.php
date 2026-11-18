<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Models\EmployeeTaxYear;
use App\Domain\Payroll\Services\TaxYearCalculator;

/**
 * Rebuilds employee_tax_years for one employee and year from scratch (never
 * incremental — spec-04 §2.9). Runs on slip submit/cancel and declaration
 * submit/cancel.
 */
final class RefreshTaxYear
{
    public function __construct(private readonly TaxYearCalculator $calculator) {}

    public function handle(int $employeeId, int $year): EmployeeTaxYear
    {
        $totals = $this->calculator->totals($employeeId, $year);
        $row = EmployeeTaxYear::query()->firstOrNew(['employee_id' => $employeeId, 'tax_year' => $year]);
        $row->fill([
            'through_period' => $totals->throughPeriod,
            'y_normal' => $totals->yNormal,
            'y_additional' => $totals->yAdditional,
            'k' => $totals->k,
            'x' => $totals->x,
            'z' => $totals->z,
            'lp' => $totals->lp,
            'cp38_total' => $totals->cp38,
            'socso_employee' => $totals->socsoEmployee,
            'eis_employee' => $totals->eisEmployee,
            'skbbk_employee' => $totals->skbbkEmployee,
            'epf_employer' => $totals->epfEmployer,
            'ea_boxes' => $totals->eaBoxes,
            'tax_exempt_used' => $totals->taxExemptUsed,
            'rebuilt_at' => now(),
        ]);
        $row->save();

        return $row;
    }
}
