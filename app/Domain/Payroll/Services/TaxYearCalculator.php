<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\DeclarationType;
use App\Domain\Payroll\Enums\EaBox;
use App\Domain\Payroll\Enums\PcbRemunerationType;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Support\TaxYearTotals;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single source of YTD figures (spec-04 §2.9): submitted slips + submitted
 * declarations + assignment opening columns. Used both for slip generation
 * (months before the slip) and to rebuild employee_tax_years (whole year).
 */
final class TaxYearCalculator
{
    /**
     * @param  CarbonImmutable|null  $before  first day of the month being calculated (null = whole year)
     */
    public function totals(int $employeeId, int $year, ?CarbonImmutable $before = null): TaxYearTotals
    {
        $yNormal = $yAdditional = $k = $x = $z = $lp = $cp38 = $socso = $eis = $skbbk = $epfEr = '0';
        $ea = [];
        $exempt = [];
        $through = null;

        $slips = SalarySlip::query()->where('employee_id', $employeeId)
            ->where('status', DocumentStatus::Submitted->value)
            ->whereYear('period', $year)
            ->when($before !== null, fn ($q) => $q->whereDate('period', '<', $before?->toDateString()))
            ->with(['lines', 'statutory'])
            ->orderBy('period')->get();

        $exemptCodes = DB::table('salary_components')->whereNotNull('tax_exemption_code')->pluck('tax_exemption_code', 'id')->all();

        foreach ($slips as $slip) {
            $through = $slip->period->toDateString();
            foreach ($slip->lines as $line) {
                $amount = (string) $line->amount;
                $isReduction = $line->kind === ComponentKind::Deduction && $line->is_wage_reduction;
                if ($line->kind === ComponentKind::Earning && $line->taxable) {
                    $taxable = Num::sub($amount, (string) $line->taxable_exempt_amount);
                    if ($line->pcb_type === PcbRemunerationType::Additional) {
                        $yAdditional = Num::add($yAdditional, $taxable);
                    } else {
                        $yNormal = Num::add($yNormal, $taxable);
                    }
                    $code = $exemptCodes[$line->salary_component_id] ?? null;
                    if ($code !== null && Num::isPositive((string) $line->taxable_exempt_amount)) {
                        $exempt[$code] = Num::add($exempt[$code] ?? '0', (string) $line->taxable_exempt_amount);
                    }
                } elseif ($isReduction && $line->taxable) {
                    $yNormal = Num::sub($yNormal, $amount);
                }

                if ($line->ea_box !== EaBox::None) {
                    if ($line->kind === ComponentKind::Earning) {
                        $ea[$line->ea_box->value] = Num::add($ea[$line->ea_box->value] ?? '0', $amount);
                    } elseif ($isReduction) {
                        $ea[$line->ea_box->value] = Num::sub($ea[$line->ea_box->value] ?? '0', $amount);
                    }
                }
            }

            $st = $slip->statutory;
            if ($st !== null) {
                $k = Num::add($k, (string) $st->epf_employee);
                $x = Num::add($x, (string) $st->pcb_net);
                // External zakat comes from the declarations below (the slip only echoes it).
                $z = Num::add($z, (string) $st->zakat_payroll);
                $cp38 = Num::add($cp38, (string) $st->cp38);
                $socso = Num::add($socso, (string) $st->socso_employee);
                $eis = Num::add($eis, (string) $st->eis_employee);
                $skbbk = Num::add($skbbk, (string) $st->skbbk_employee);
                $epfEr = Num::add($epfEr, (string) $st->epf_employer);
            }
        }

        $declarations = TaxDeclaration::query()->where('employee_id', $employeeId)
            ->where('status', DocumentStatus::Submitted->value)
            ->where('tax_year', $year)
            ->with('lines')->get();
        foreach ($declarations as $d) {
            if ($d->type === DeclarationType::Tp3) {
                if ($before !== null && $d->period->greaterThan($before)) {
                    continue;
                }
                $yNormal = Num::add($yNormal, (string) $d->prev_gross_normal);
                $yAdditional = Num::add($yAdditional, (string) $d->prev_gross_additional);
                $k = Num::add($k, (string) $d->prev_epf);
                $x = Num::add($x, (string) $d->prev_pcb);
                $z = Num::add($z, (string) $d->prev_zakat);
                $cp38 = Num::add($cp38, (string) $d->prev_cp38);
                $lp = Num::add($lp, (string) $d->prev_lp);

                continue;
            }
            if ($before !== null && ! $d->period->lessThan($before)) {
                continue;
            }
            if ($d->type === DeclarationType::Tp1) {
                foreach ($d->lines as $line) {
                    $lp = Num::add($lp, (string) $line->amount);
                }
            } elseif ($d->type === DeclarationType::ZakatExternal) {
                $z = Num::add($z, (string) $d->zakat_amount);
            }
        }

        $opening = SalaryAssignment::query()->where('employee_id', $employeeId)
            ->where('status', DocumentStatus::Submitted->value)
            ->where('opening_year', $year)
            ->orderBy('effective_from')->first();
        if ($opening !== null) {
            $yNormal = Num::add($yNormal, $opening->opening_gross_normal);
            $yAdditional = Num::add($yAdditional, $opening->opening_gross_additional);
            $k = Num::add($k, $opening->opening_epf_employee);
            $x = Num::add($x, $opening->opening_pcb);
            $z = Num::add($z, $opening->opening_zakat);
            $cp38 = Num::add($cp38, $opening->opening_cp38);
            $socso = Num::add($socso, $opening->opening_socso_employee);
            $eis = Num::add($eis, $opening->opening_eis_employee);
            $skbbk = Num::add($skbbk, $opening->opening_skbbk_employee);
            $lp = Num::add($lp, $opening->opening_lp);
            $breakdown = $opening->opening_ea_breakdown;
            if ($breakdown === null || $breakdown === []) {
                $breakdown = [EaBox::B1aSalary->value => Num::add($opening->opening_gross_normal, $opening->opening_gross_additional)];
            }
            foreach ($breakdown as $box => $amount) {
                $ea[$box] = Num::add($ea[$box] ?? '0', (string) $amount);
            }
        }

        return new TaxYearTotals(
            $year, $through,
            Num::money($yNormal), Num::money($yAdditional), Num::money($k), Num::money($x), Num::money($z), Num::money($lp),
            Num::money($cp38), Num::money($socso), Num::money($eis), Num::money($skbbk), Num::money($epfEr),
            array_map(fn (string $v) => Num::money($v), $ea),
            array_map(fn (string $v) => Num::money($v), $exempt),
        );
    }
}
