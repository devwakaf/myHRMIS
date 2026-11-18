<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Attendance\Models\OvertimeType;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Payroll\Formula\Parser;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Models\SalaryStructureRow;
use App\Domain\Shared\Organisation\OrganisationContext;

/**
 * Per-Organisasi payroll defaults (spec-04 §7.3): Tetapan Payroll, the 27
 * Malaysian components (our own codes and names), the "Struktur Standard
 * Bulanan" structure, and default OT / encashment components for Attendance
 * and Leave types that have none. Idempotent. Flags marked "?" in the spec are
 * defaults whose statutory treatment is UNVERIFIED-STAT (open Q11).
 */
final class SeedPayrollDefaults
{
    public const STRUCTURE_CODE = 'STANDARD';

    /**
     * code, BM, EN, kind, method, statutory key, flags (E S I H T P), pcb type, EA box, extra attributes.
     */
    private const COMPONENTS = [
        ['GAJI_POKOK', 'Gaji Pokok', 'Basic Salary', 'earning', 'formula', null, 'ESIHTP', 'normal', 'b1a_salary', ['default_formula' => 'BASE', 'ot_wage_base' => true, 'arrear_eligible' => true]],
        ['ELAUN_TETAP', 'Elaun Tetap', 'Fixed Allowance', 'earning', 'fixed', null, 'ESIHTP', 'normal', 'b1c_allowances_perquisites', ['arrear_eligible' => true, 'default_amount' => '0']],
        ['ELAUN_PERUMAHAN', 'Elaun Perumahan', 'Housing Allowance', 'earning', 'fixed', null, 'ESIHTP', 'normal', 'b1c_allowances_perquisites'],
        ['ELAUN_PERJALANAN', 'Elaun Perjalanan', 'Travel Allowance', 'earning', 'fixed', null, 'T', 'normal', 'b1c_allowances_perquisites', ['tax_exemption_code' => 'travel_petrol_toll']],
        ['ELAUN_TELEFON', 'Elaun Telefon', 'Phone Allowance', 'earning', 'fixed', null, 'T', 'normal', 'b1c_allowances_perquisites', ['tax_exemption_code' => 'phone_broadband']],
        ['ELAUN_SHIFT', 'Elaun Shift', 'Shift Allowance', 'earning', 'input', null, 'ESIT', 'normal', 'b1c_allowances_perquisites'],
        ['KERJA_LEBIH_MASA', 'Bayaran Kerja Lebih Masa (OT)', 'Overtime Pay (OT)', 'earning', 'input', null, 'SIT', 'normal', 'b1a_salary'],
        ['BONUS', 'Bonus', 'Bonus', 'earning', 'input', null, 'ET', 'additional', 'b1b_fees_commission_bonus', ['is_epf_bonus' => true]],
        ['KOMISEN', 'Komisen', 'Commission', 'earning', 'input', null, 'ESIT', 'additional', 'b1b_fees_commission_bonus'],
        ['INSENTIF', 'Insentif', 'Incentive', 'earning', 'input', null, 'ESIT', 'additional', 'b1b_fees_commission_bonus'],
        ['TUNGGAKAN_GAJI', 'Tunggakan Gaji', 'Salary Arrears', 'earning', 'input', null, 'ESIHT', 'additional', 'b1a_salary'],
        ['GANTIAN_CUTI', 'Gantian Tunai Cuti', 'Leave Encashment', 'earning', 'input', null, 'ESIT', 'additional', 'b1a_salary'],
        ['PELARASAN_PENDAPATAN', 'Pelarasan Pendapatan', 'Earnings Adjustment', 'earning', 'input', null, 'ESIHT', 'additional', 'b1a_salary'],
        ['KWSP_PEKERJA', 'KWSP (Pekerja)', 'EPF (Employee)', 'deduction', 'statutory', 'epf_employee', '', 'normal', 'none'],
        ['PERKESO_PEKERJA', 'PERKESO (Pekerja)', 'SOCSO (Employee)', 'deduction', 'statutory', 'socso_employee', '', 'normal', 'none'],
        ['SKBBK_PEKERJA', 'LINDUNG 24 Jam (SKBBK)', 'LINDUNG 24 Jam (SKBBK)', 'deduction', 'statutory', 'skbbk_employee', '', 'normal', 'none'],
        ['SIP_PEKERJA', 'SIP (Pekerja)', 'EIS (Employee)', 'deduction', 'statutory', 'eis_employee', '', 'normal', 'none'],
        ['PCB', 'PCB', 'PCB (MTD)', 'deduction', 'statutory', 'pcb', '', 'normal', 'none'],
        ['CP38', 'Potongan CP38', 'CP38 Deduction', 'deduction', 'input', 'cp38', '', 'normal', 'none'],
        ['ZAKAT', 'Zakat', 'Zakat', 'deduction', 'input', 'zakat', '', 'normal', 'none'],
        ['POTONGAN_CTG', 'Potongan Cuti Tanpa Gaji', 'Unpaid Leave Deduction', 'deduction', 'statutory', 'lwp_deduction', 'ESIHT', 'normal', 'b1a_salary', ['is_wage_reduction' => true]],
        ['PELARASAN_POTONGAN', 'Pelarasan Potongan', 'Deduction Adjustment', 'deduction', 'input', null, '', 'normal', 'none'],
        ['POTONGAN_LAIN', 'Potongan Lain-lain', 'Other Deductions', 'deduction', 'input', null, '', 'normal', 'none'],
        ['KWSP_MAJIKAN', 'KWSP (Majikan)', 'EPF (Employer)', 'employer_contribution', 'statutory', 'epf_employer', '', 'normal', 'none'],
        ['PERKESO_MAJIKAN', 'PERKESO (Majikan)', 'SOCSO (Employer)', 'employer_contribution', 'statutory', 'socso_employer', '', 'normal', 'none'],
        ['SIP_MAJIKAN', 'SIP (Majikan)', 'EIS (Employer)', 'employer_contribution', 'statutory', 'eis_employer', '', 'normal', 'none'],
        ['LEVI_HRDC', 'Levi HRD Corp', 'HRD Corp Levy', 'employer_contribution', 'statutory', 'hrdc_levy', '', 'normal', 'none'],
    ];

    /** Rows of the default structure (statutory rows carry no amount). */
    private const STRUCTURE = [
        'GAJI_POKOK', 'ELAUN_TETAP', 'KWSP_PEKERJA', 'PERKESO_PEKERJA', 'SKBBK_PEKERJA', 'SIP_PEKERJA', 'PCB', 'ZAKAT', 'CP38',
        'POTONGAN_CTG', 'KWSP_MAJIKAN', 'PERKESO_MAJIKAN', 'SIP_MAJIKAN', 'LEVI_HRDC',
    ];

    public function handle(int $organisationId): void
    {
        OrganisationContext::run($organisationId, function () use ($organisationId): void {
            PayrollSettings::current($organisationId);
            $parser = new Parser;

            foreach (self::COMPONENTS as $i => $def) {
                [$code, $ms, $en, $kind, $method, $key, $flags, $pcbType, $eaBox] = $def;
                $extra = $def[9] ?? [];
                if (SalaryComponent::query()->where('code', $code)->exists()) {
                    continue;
                }
                $component = new SalaryComponent;
                $component->forceFill(array_merge([
                    'code' => $code, 'name_ms' => $ms, 'name_en' => $en, 'kind' => $kind, 'calculation' => $method,
                    'statutory_key' => $key, 'pcb_type' => $pcbType, 'ea_box' => $eaBox, 'sort_order' => ($i + 1) * 10,
                    'is_system' => $key !== null || in_array($code, ['GAJI_POKOK', 'TUNGGAKAN_GAJI', 'GANTIAN_CUTI', 'PELARASAN_PENDAPATAN', 'PELARASAN_POTONGAN', 'KERJA_LEBIH_MASA'], true),
                    'epf_wage' => str_contains($flags, 'E'), 'socso_wage' => str_contains($flags, 'S'), 'eis_wage' => str_contains($flags, 'I'),
                    'hrdc_wage' => str_contains($flags, 'H'), 'taxable' => str_contains($flags, 'T'), 'prorate' => str_contains($flags, 'P'),
                    'include_in_gross' => $kind === 'earning',
                ], $extra));
                if (isset($extra['default_formula'])) {
                    $component->default_formula_ast = $parser->parse((string) $extra['default_formula'])->toArray();
                }
                $component->save();
            }

            if (! SalaryStructure::query()->where('code', self::STRUCTURE_CODE)->exists()) {
                $structure = new SalaryStructure;
                $structure->forceFill(['code' => self::STRUCTURE_CODE, 'name_ms' => 'Struktur Standard Bulanan', 'name_en' => 'Standard Monthly Structure', 'last_validated_at' => now()])->save();
                $ids = SalaryComponent::query()->whereIn('code', self::STRUCTURE)->pluck('id', 'code');
                foreach (self::STRUCTURE as $position => $code) {
                    if (isset($ids[$code])) {
                        SalaryStructureRow::query()->create([
                            'salary_structure_id' => $structure->id, 'salary_component_id' => $ids[$code], 'sort_order' => $position + 1,
                            'referenced_codes' => $code === 'GAJI_POKOK' ? ['BASE'] : [],
                        ]);
                    }
                }
            }

            $this->linkDefaults();
        });
    }

    /** Default OT component for Attendance OT types and encashment component for Leave types (§7.3). */
    private function linkDefaults(): void
    {
        $ot = SalaryComponent::query()->where('code', 'KERJA_LEBIH_MASA')->value('id');
        $encash = SalaryComponent::query()->where('code', 'GANTIAN_CUTI')->value('id');
        if ($ot !== null && class_exists(OvertimeType::class)) {
            OvertimeType::query()->whereNull('salary_component_id')->update(['salary_component_id' => $ot]);
        }
        if ($encash !== null) {
            LeaveType::query()->whereNull('encashment_salary_component_id')->update(['encashment_salary_component_id' => $encash]);
        }
    }
}
