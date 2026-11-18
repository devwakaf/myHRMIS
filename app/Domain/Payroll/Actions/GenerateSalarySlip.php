<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeSnapshot;
use App\Domain\Payroll\Enums\GenerationStatus;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Exceptions\PayrollCalculationException;
use App\Domain\Payroll\Models\AdditionalSalaryApplication;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalarySlipLine;
use App\Domain\Payroll\Models\SalarySlipStatutory;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Models\SalaryWithholding;
use App\Domain\Payroll\Models\SalaryWithholdingCycle;
use App\Domain\Payroll\Services\OvertimePosting;
use App\Domain\Payroll\Services\SlipCalculator;
use App\Domain\Payroll\Services\SlipContextBuilder;
use App\Domain\Payroll\Support\LineResult;
use App\Domain\Payroll\Support\PaymentDays;
use App\Domain\Payroll\Support\SlipInput;
use App\Domain\Payroll\Support\SlipResult;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Statutory\Enums\RateScheme;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Domain\Statutory\Services\StatutoryRateRepository;
use Illuminate\Support\Facades\DB;

/**
 * Generates (or regenerates) one employee's draft Slip Gaji in a run
 * (spec-04 §4, §5.2): preconditions, OT posting, calculation and persistence of
 * slip, lines, statutory row, Additional Salary applications and withholding link.
 * Throws PayrollCalculationException / FormulaError for per-employee failures.
 */
final class GenerateSalarySlip
{
    public function __construct(
        private readonly SlipContextBuilder $builder,
        private readonly SlipCalculator $calculator,
        private readonly OvertimePosting $overtime,
        private readonly StatutoryRateRepository $rates,
    ) {}

    public function handle(PayrollRun $run, PayrollRunEmployee $runEmployee): SalarySlip
    {
        if (! in_array($run->status, [PayrollRunStatus::Draft, PayrollRunStatus::SlipsGenerated], true)) {
            throw PayrollCalculationException::of('run_locked');
        }

        // A failed regeneration must not leave the previous (stale) draft behind.
        DB::transaction(fn () => $this->discardDraft(PayrollRunEmployee::query()->lockForUpdate()->findOrFail($runEmployee->id)));

        return DB::transaction(function () use ($run, $runEmployee): SalarySlip {
            /** @var PayrollRunEmployee $row */
            $row = PayrollRunEmployee::query()->lockForUpdate()->findOrFail($runEmployee->id);
            $employee = Employee::query()->findOrFail($row->employee_id);
            $this->preconditions($run, $employee);

            $actualStart = $run->period_start->max($employee->date_of_joining);
            $actualEnd = $employee->relieving_date === null ? $run->period_end : $run->period_end->min($employee->relieving_date);
            $this->overtime->postFor($run, $employee, $actualStart, $actualEnd);

            $built = $this->builder->build(
                $employee, $run->period,
                $row->lwp_override_days === null ? null : (string) $row->lwp_override_days,
                $row->pcb_override_amount === null ? null : (string) $row->pcb_override_amount,
            );
            $result = $this->calculator->calculate($built['input']);

            $warnings = array_values(array_unique([...$built['warnings'], ...$result->warnings, ...$this->extraWarnings($employee, $run, $result, (string) $built['assignment']->base_salary)]));
            $slip = $this->persist($run, $row, $employee, $built, $result, $warnings);

            $row->forceFill([
                'generation_status' => $result->hasErrors ? GenerationStatus::Failed : ($warnings === [] ? GenerationStatus::Success : GenerationStatus::Warning),
                'error_code' => $result->hasErrors ? 'negative_net' : null,
                'error_message' => $result->hasErrors ? (string) __('payroll.warnings.negative_net') : null,
                'warnings' => $warnings,
                'salary_slip_id' => $slip->id,
                'attempts' => $row->attempts + 1,
            ])->save();

            return $slip;
        });
    }

    private function preconditions(PayrollRun $run, Employee $employee): void
    {
        if ($employee->date_of_joining->greaterThan($run->period_end)
            || ($employee->relieving_date !== null && $employee->relieving_date->lessThan($run->period_start))) {
            throw PayrollCalculationException::of('not_employed_in_period');
        }

        $other = SalarySlip::query()->where('active_period_key', $run->periodKey($employee->id))
            ->where('payroll_run_id', '!=', $run->id)->first();
        if ($other !== null) {
            throw PayrollCalculationException::of('slip_exists', ['number' => $other->number]);
        }

        $earlier = PayrollRunEmployee::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_employees.payroll_run_id')
            ->where('payroll_run_employees.employee_id', $employee->id)
            ->where('payroll_run_employees.included', true)
            ->whereIn('payroll_runs.status', [PayrollRunStatus::Draft->value, PayrollRunStatus::SlipsGenerated->value])
            ->whereDate('payroll_runs.period', '<', $run->period->toDateString())
            ->whereYear('payroll_runs.period', $run->period->year)
            ->value('payroll_runs.number');
        if ($earlier !== null) {
            throw PayrollCalculationException::of('earlier_run_pending', ['number' => (string) $earlier]);
        }
    }

    /** Delete the previous draft slip of this run employee (regeneration). */
    public function discardDraft(PayrollRunEmployee $row): void
    {
        if ($row->salary_slip_id === null) {
            return;
        }
        $slip = SalarySlip::query()->find($row->salary_slip_id);
        if ($slip !== null && $slip->status === DocumentStatus::Draft) {
            SalaryWithholdingCycle::query()->where('salary_slip_id', $slip->id)->update(['salary_slip_id' => null]);
            AdditionalSalaryApplication::query()->where('salary_slip_id', $slip->id)->delete();
            $slip->delete();
        }
        $row->forceFill(['salary_slip_id' => null])->save();
    }

    /**
     * Variance vs the previous submitted slip and minimum wage (spec-04 §4.8).
     *
     * @return list<string>
     */
    private function extraWarnings(Employee $employee, PayrollRun $run, SlipResult $result, string $base): array
    {
        $warnings = [];
        $settings = PayrollSettings::current($run->organisation_id);

        $previous = SalarySlip::query()->where('employee_id', $employee->id)->where('status', DocumentStatus::Submitted->value)
            ->whereDate('period', '<', $run->period->toDateString())->orderByDesc('period')->first();
        if ($previous !== null && Num::isPositive((string) $previous->net_pay)) {
            $change = Num::div(Num::mul(Num::sub($result->net, (string) $previous->net_pay), '100'), (string) $previous->net_pay);
            if (Num::cmp(Num::max($change, Num::neg($change)), (string) $settings->variance_threshold_percent) > 0) {
                $warnings[] = (string) __('payroll.warnings.variance', ['percent' => Num::fixed($change, 1)]);
            }
        }

        if ($settings->min_wage_check) {
            $set = $this->rates->setFor(RateScheme::MinimumWage, $run->period_end);
            $minimum = $set === null ? null : $this->rates->minimumWage($set);
            if ($minimum !== null && Num::cmp($base, $minimum->monthly) < 0) {
                $warnings[] = (string) __('payroll.warnings.below_minimum_wage', ['amount' => $minimum->monthly]);
            }
        }

        return $warnings;
    }

    /**
     * @param  array{input: SlipInput, assignment: SalaryAssignment, structure: SalaryStructure, days: array<string, mixed>, warnings: list<string>, rate_sets: list<StatutoryRateSet>}  $built
     * @param  list<string>  $warnings
     */
    private function persist(PayrollRun $run, PayrollRunEmployee $row, Employee $employee, array $built, SlipResult $result, array $warnings): SalarySlip
    {
        /** @var PaymentDays $days */
        $days = $built['input']->days;
        $snap = EmployeeSnapshot::asAt($employee, $run->period_end);
        $tax = $employee->taxProfile;
        $assignment = $built['assignment'];

        $slip = new SalarySlip;
        $slip->fill([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'period' => $run->period->toDateString(),
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'actual_start' => $built['days']['actual_start']->toDateString(),
            'actual_end' => $built['days']['actual_end']->toDateString(),
            'payment_date' => $run->payment_date->toDateString(),
            'payment_status' => PaymentStatus::Unpaid,
            'active_period_key' => $run->periodKey($employee->id),
            'salary_assignment_id' => $assignment->id,
            'salary_structure_id' => $built['structure']->id,
            'structure_revision' => $built['structure']->revision,
            'calc_version' => SalarySlip::CALC_VERSION,
            'employee_no' => $employee->employee_no,
            'full_name' => $employee->full_name,
            'identity_type' => $employee->identity_type->value,
            'ic_no' => $employee->ic_no,
            'old_ic_no' => $employee->old_ic_no,
            'passport_no' => $employee->passport_no,
            'passport_country' => $employee->passport_country,
            'citizenship_status' => $employee->citizenship_status->value,
            'date_of_birth' => $employee->date_of_birth->toDateString(),
            'age_years' => $employee->ageOn($run->period_end),
            'branch_id' => $snap->branchId,
            'department_id' => $snap->departmentId,
            'designation_id' => $snap->designationId,
            'grade_id' => $snap->gradeId,
            'cost_centre_code' => $snap->departmentId === null ? null : Department::query()->whereKey($snap->departmentId)->value('cost_centre_code'),
            'payment_mode' => $employee->salary_payment_mode->value,
            'bank_id' => $employee->getAttribute('bank_id'),
            'bank_account_no' => $employee->getAttribute('bank_account_no'),
            'bank_account_name' => $employee->getAttribute('bank_account_name'),
            'kwsp_no' => $employee->getAttribute('kwsp_no'),
            'perkeso_no' => $employee->getAttribute('perkeso_no'),
            'tin' => $tax?->tin,
            'tax_residency' => $tax?->tax_residency->value,
            'pcb_category' => $tax->pcb_category ?? 1,
            'mtd_scheme' => $tax?->mtd_scheme->value,
            'children' => $tax === null ? null : [
                'basic' => $tax->children_basic, 'higher_ed' => $tax->children_higher_ed,
                'disabled' => $tax->children_disabled, 'disabled_higher_ed' => $tax->children_disabled_higher_ed,
            ],
            'calendar_days' => $days->calendarDays,
            'working_days' => $days->workingDays,
            'rate_basis' => $days->basis,
            'divisor' => $days->divisor,
            'outside_employment_days' => $days->outsideDays,
            'lwp_days' => $days->lwpDays,
            'absent_days' => $days->absentDays,
            'unmarked_days' => $days->unmarkedDays,
            'unpaid_days_total' => Num::fixed($days->unpaidTotal(), 3),
            'payment_fraction' => Num::fixed($days->fraction(), 10),
            'payment_days' => $days->paymentDays(),
            'lwp_override_days' => $row->lwp_override_days,
            'lwp_override_reason' => $row->lwp_override_reason,
            'gross_pay' => $result->gross,
            'total_deductions' => $result->deductions,
            'net_pay' => $result->net,
            'net_rounding_adjustment' => $result->netRoundingAdjustment,
            'employer_total' => $result->employerTotal,
            'pcb_override_amount' => $row->pcb_override_amount,
            'pcb_override_reason' => $row->pcb_override_reason,
            'warnings' => $warnings,
            'has_errors' => $result->hasErrors,
            'calc_trace' => $result->trace + [
                'unpaid_items' => $built['days']['unpaid_items'],
                'absent_dates' => $built['days']['absent_dates'],
                'unmarked_dates' => $built['days']['unmarked_dates'],
                'rate_sets' => array_map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'status' => $s->status->value], $built['rate_sets']),
            ],
        ]);
        $slip->setAttribute('organisation_id', $run->organisation_id);
        $slip->save();

        foreach ($result->lines as $line) {
            $this->persistLine($slip, $line);
        }

        $this->persistStatutory($slip, $result, $built['input']->statutory->ytd->toArray());
        $this->linkWithholding($slip);

        SalaryComponent::query()->whereIn('id', array_map(fn (LineResult $l) => $l->component->id, $result->lines))
            ->whereNull('first_used_at')->update(['first_used_at' => now()]);

        return $slip;
    }

    private function persistLine(SalarySlip $slip, LineResult $line): void
    {
        $c = $line->component;
        SalarySlipLine::query()->create([
            'salary_slip_id' => $slip->id,
            'kind' => $c->kind,
            'salary_component_id' => $c->id,
            'code' => $c->code,
            'name_ms' => $c->nameMs,
            'name_en' => $c->nameEn,
            'sort_order' => $c->sortOrder,
            'amount' => $line->amount,
            'full_cycle_amount' => $line->fullCycle,
            'structure_amount' => $line->structureAmount,
            'additional_amount' => $line->additionalAmount,
            'source' => $line->source,
            'additional_salary_id' => count($line->applications) === 1 ? $line->applications[0]['id'] : null,
            'quantity' => $line->quantity,
            'taxable_exempt_amount' => $line->exemptAmount,
            'include_in_gross' => $line->flag('include_in_gross'),
            'epf_wage' => $line->flag('epf_wage'),
            'socso_wage' => $line->flag('socso_wage'),
            'eis_wage' => $line->flag('eis_wage'),
            'hrdc_wage' => $line->flag('hrdc_wage'),
            'taxable' => $line->flag('taxable'),
            'pcb_type' => $c->pcbType,
            'ea_box' => $c->eaBox,
            'is_epf_bonus' => $line->flag('is_epf_bonus'),
            'ot_wage_base' => $line->flag('ot_wage_base'),
            'is_wage_reduction' => $line->flag('is_wage_reduction'),
            'show_on_slip' => $line->visible,
            'note' => $line->note === null ? null : mb_substr($line->note, 0, 255),
        ]);

        foreach ($line->applications as $application) {
            AdditionalSalaryApplication::query()->create([
                'additional_salary_id' => $application['id'],
                'salary_slip_id' => $slip->id,
                'period' => $slip->period->toDateString(),
                'amount' => $application['amount'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $ytd
     */
    private function persistStatutory(SalarySlip $slip, SlipResult $r, array $ytd): void
    {
        $mtdNormal = $r->mtd->pcbNormal ?? '0.00';
        SalarySlipStatutory::query()->create([
            'salary_slip_id' => $slip->id,
            'epf_part' => $r->epf->part->value ?? 'none',
            'epf_wage' => $r->epf->wage ?? '0.00',
            'epf_wage_excl_bonus' => $r->epf->wageExclBonus ?? '0.00',
            'epf_employee' => $r->epf->employee ?? '0.00',
            'epf_employer' => $r->epf->employer ?? '0.00',
            'epf_employee_normal' => $r->epf->employeeNormal ?? '0.00',
            'epf_method' => $r->epf->method ?? 'none',
            'epf_rate_set_id' => $r->epf?->rateSetId,
            'socso_category' => $r->socso->category->value ?? 'none',
            'socso_wage' => $r->socso->wage ?? '0.00',
            'socso_employee' => $r->socso->employee ?? '0.00',
            'socso_employer' => $r->socso->employer ?? '0.00',
            'skbbk_applies' => $r->socso->skbbkApplies ?? false,
            'skbbk_employee' => $r->socso->skbbkEmployee ?? '0.00',
            'socso_rate_set_id' => $r->socso?->rateSetId,
            'eis_eligible' => $r->eis->eligible ?? false,
            'eis_wage' => $r->eis->wage ?? '0.00',
            'eis_employee' => $r->eis->employee ?? '0.00',
            'eis_employer' => $r->eis->employer ?? '0.00',
            'eis_rate_set_id' => $r->eis?->rateSetId,
            'hrdc_applies' => $r->hrdc['applies'] ?? false,
            'hrdc_wage' => $r->hrdc['wage'] ?? '0.00',
            'hrdc_levy' => $r->hrdc['levy'] ?? '0.00',
            'hrdc_rate' => $r->hrdc['rate'] ?? '0',
            'hrdc_rate_set_id' => $r->hrdc['rate_set_id'] ?? null,
            'pcb_method' => $r->pcbMethod,
            'pcb_normal' => $mtdNormal,
            'pcb_additional' => $r->mtd->pcbAdditional ?? '0.00',
            'pcb_before_zakat' => $r->mtd->pcbBeforeZakat ?? $r->pcbLine,
            'zakat_payroll' => $r->zakatPayroll,
            'zakat_external' => $r->zakatExternal,
            'pcb_net' => $r->pcbLine,
            'cp38' => $r->cp38,
            'mtd_rate_set_id' => $r->mtd?->rateSetId,
            'pcb_inputs' => $r->mtd->inputs ?? ($r->trace['pcb'] ?? null),
            'ytd_snapshot' => $ytd,
        ]);
    }

    /** Tahan Gaji: a submitted withholding covering the month makes the slip Ditahan (spec-04 §5.4). */
    private function linkWithholding(SalarySlip $slip): void
    {
        $withholding = SalaryWithholding::query()->where('employee_id', $slip->employee_id)
            ->where('status', DocumentStatus::Submitted->value)
            ->whereDate('from_period', '<=', $slip->period->toDateString())
            ->get()->first(fn (SalaryWithholding $w) => $w->covers($slip->period));
        if ($withholding === null) {
            return;
        }

        $cycle = SalaryWithholdingCycle::query()->firstOrCreate(
            ['salary_withholding_id' => $withholding->id, 'period' => $slip->period->toDateString()],
        );
        if ($cycle->released_at !== null) {
            return;
        }
        $cycle->forceFill(['salary_slip_id' => $slip->id])->save();
        $slip->forceFill(['salary_withholding_cycle_id' => $cycle->id, 'payment_status' => PaymentStatus::Withheld])->save();
    }
}
