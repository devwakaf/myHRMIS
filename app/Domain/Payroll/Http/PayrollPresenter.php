<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http;

use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\Arrear;
use App\Domain\Payroll\Models\EaForm;
use App\Domain\Payroll\Models\PayrollCorrection;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalarySlipLine;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Models\SalaryStructureRow;
use App\Domain\Payroll\Models\SalaryWithholding;
use App\Domain\Payroll\Models\SalaryWithholdingCycle;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Support\SlipResult;
use App\Domain\Statutory\Models\StatutoryRateSet;

/**
 * Inertia prop shapes for the Payroll / Statutori pages (money as "0.00"
 * strings, dates Y-m-d, enums as {value,label} where useful). The browser
 * never computes money (spec-00 A8).
 */
final class PayrollPresenter
{
    /** @var array<int, string>|null */
    private static ?array $componentCodes = null;

    /** Code of a salary component (memoised per request). */
    public static function componentCode(int $id): string
    {
        self::$componentCodes ??= SalaryComponent::query()->pluck('code', 'id')->all();

        return self::$componentCodes[$id] ?? (string) $id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function component(SalaryComponent $c): array
    {
        return $c->only([
            'id', 'code', 'name_ms', 'name_en', 'statutory_key', 'default_amount', 'default_formula', 'default_condition', 'prorate',
            'include_in_gross', 'show_on_slip', 'show_if_zero', 'epf_wage', 'socso_wage', 'eis_wage', 'hrdc_wage', 'taxable',
            'tax_exemption_code', 'is_epf_bonus', 'ot_wage_base', 'arrear_eligible', 'is_wage_reduction', 'arrear_component_id',
            'sort_order', 'is_system', 'is_active', 'description_ms', 'description_en',
        ]) + [
            'kind' => $c->kind->value, 'kind_label' => $c->kind->label(), 'calculation' => $c->calculation->value,
            'statutory_key' => $c->statutory_key?->value, 'pcb_type' => $c->pcb_type->value, 'ea_box' => $c->ea_box->value,
            'rounding_mode' => $c->rounding_mode->value, 'used' => $c->first_used_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function structure(SalaryStructure $s, bool $withRows = false): array
    {
        $data = [
            'id' => $s->id, 'code' => $s->code, 'name_ms' => $s->name_ms, 'name_en' => $s->name_en, 'name' => $s->displayName(),
            'description' => $s->description, 'revision' => $s->revision, 'is_active' => $s->is_active,
            'last_validated_at' => $s->last_validated_at?->format('Y-m-d H:i'),
            'employee_count' => SalaryAssignment::query()->where('salary_structure_id', $s->id)->where('status', 'submitted')
                ->whereNull('effective_to')->count(),
        ];
        if ($withRows) {
            $data['rows'] = $s->rows()->with('component')->get()->map(fn (SalaryStructureRow $r) => [
                'id' => $r->id, 'salary_component_id' => $r->salary_component_id, 'code' => $r->component->code,
                'name' => $r->component->displayName(), 'kind' => $r->component->kind->value, 'sort_order' => $r->sort_order,
                'calculation_override' => $r->calculation_override?->value, 'calculation' => ($r->calculation_override ?? $r->component->calculation)->value,
                'amount' => $r->amount, 'formula' => $r->formula, 'condition' => $r->condition, 'prorate_override' => $r->prorate_override,
                'referenced_codes' => $r->referenced_codes ?? [],
            ])->values()->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function assignment(SalaryAssignment $a): array
    {
        return [
            'id' => $a->id, 'number' => $a->number, 'employee_id' => $a->employee_id, 'employee' => $a->employee?->full_name,
            'employee_no' => $a->employee?->employee_no, 'salary_structure_id' => $a->salary_structure_id,
            'structure' => $a->structure?->displayName(), 'effective_from' => $a->effective_from->toDateString(),
            'effective_to' => $a->effective_to?->toDateString(), 'base_salary' => (string) $a->base_salary,
            'reason' => $a->reason->value, 'reason_label' => $a->reason->label(), 'status' => $a->status->value, 'status_label' => $a->status->label(),
            'epf_employee_rate_override' => $a->epf_employee_rate_override, 'epf_employer_rate_override' => $a->epf_employer_rate_override,
            'opening_year' => $a->opening_year, 'opening_source' => $a->opening_source,
            'opening' => array_combine(SalaryAssignment::OPENING_COLUMNS, array_map(fn (string $c) => (string) $a->getAttribute($c), SalaryAssignment::OPENING_COLUMNS)),
            'amounts' => $a->amounts()->with('component')->get()->map(fn ($x) => ['salary_component_id' => $x->salary_component_id, 'code' => $x->component?->code, 'amount' => (string) $x->amount])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(PayrollRun $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'period' => $r->period->format('Y-m'), 'period_start' => $r->period_start->toDateString(),
            'period_end' => $r->period_end->toDateString(), 'payment_date' => $r->payment_date->toDateString(),
            'status' => $r->status->value, 'status_label' => $r->status->label(),
            'processing_state' => $r->processing_state->value, 'processing_label' => $r->processing_state->label(), 'processing_error' => $r->processing_error,
            'filters' => $r->filters ?? [], 'notes' => $r->notes, 'employee_count' => $r->employee_count, 'withheld_count' => $r->withheld_count,
            'gross_total' => (string) $r->gross_total, 'deduction_total' => (string) $r->deduction_total, 'net_total' => (string) $r->net_total,
            'employer_total' => (string) $r->employer_total, 'prepared_by' => $r->prepared_by, 'generated_by' => $r->generated_by,
            'submitted_at' => $r->submitted_at?->format('Y-m-d H:i'), 'submitted_by' => $r->submitted_by,
            'paid_at' => $r->paid_at?->toDateString(), 'payment_reference' => $r->payment_reference,
            'rate_set_ids' => $r->rate_set_ids ?? [], 'unverified_acknowledged' => $r->unverified_acknowledged,
            'ytd_refreshed_at' => $r->ytd_refreshed_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function runEmployee(PayrollRunEmployee $row, ?SalarySlip $slip): array
    {
        return [
            'id' => $row->id, 'employee_id' => $row->employee_id, 'employee_no' => $row->employee?->employee_no, 'full_name' => $row->employee?->full_name,
            'included' => $row->included, 'exclusion_reason' => $row->exclusion_reason,
            'generation_status' => $row->generation_status->value, 'generation_label' => $row->generation_status->label(),
            'error_message' => $row->error_message, 'warnings' => $row->warnings ?? [], 'attempts' => $row->attempts,
            'lwp_override_days' => $row->lwp_override_days, 'pcb_override_amount' => $row->pcb_override_amount,
            'slip' => $slip === null ? null : [
                'id' => $slip->id, 'number' => $slip->number, 'payment_days' => (string) $slip->payment_days, 'lwp_days' => (string) $slip->lwp_days,
                'gross_pay' => (string) $slip->gross_pay, 'total_deductions' => (string) $slip->total_deductions, 'net_pay' => (string) $slip->net_pay,
                'employer_total' => (string) $slip->employer_total, 'has_errors' => $slip->has_errors, 'payment_status' => $slip->payment_status->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function slip(SalarySlip $s, bool $withTrace = false, bool $maskIc = true): array
    {
        $ic = $s->ic_no;
        if ($maskIc && $ic !== null && strlen($ic) >= 4) {
            $ic = str_repeat('*', strlen($ic) - 4).substr($ic, -4);
        }
        $st = $s->statutory;

        return [
            'id' => $s->id, 'number' => $s->number, 'payroll_run_id' => $s->payroll_run_id, 'employee_id' => $s->employee_id,
            'period' => $s->period->format('Y-m'), 'actual_start' => $s->actual_start->toDateString(), 'actual_end' => $s->actual_end->toDateString(),
            'payment_date' => $s->payment_date->toDateString(), 'status' => $s->status->value, 'status_label' => $s->status->label(),
            'payment_status' => $s->payment_status->value, 'payment_status_label' => $s->payment_status->label(),
            'employee' => [
                'employee_no' => $s->employee_no, 'full_name' => $s->full_name, 'ic_no' => $ic, 'passport_no' => $s->passport_no,
                'kwsp_no' => $s->kwsp_no, 'perkeso_no' => $s->perkeso_no, 'tin' => $s->tin, 'department_id' => $s->department_id,
                'designation_id' => $s->designation_id, 'bank_account_last4' => $s->bank_account_no === null ? null : substr($s->bank_account_no, -4),
            ],
            'days' => [
                'calendar_days' => (string) $s->calendar_days, 'working_days' => $s->working_days, 'rate_basis' => $s->rate_basis, 'divisor' => (string) $s->divisor,
                'outside_employment_days' => (string) $s->outside_employment_days, 'lwp_days' => (string) $s->lwp_days, 'absent_days' => (string) $s->absent_days,
                'unmarked_days' => (string) $s->unmarked_days, 'payment_days' => (string) $s->payment_days, 'payment_fraction' => (string) $s->payment_fraction,
            ],
            'lines' => $s->lines->map(fn (SalarySlipLine $l) => [
                'code' => $l->code, 'name' => $l->displayName(), 'kind' => $l->kind->value, 'amount' => (string) $l->amount,
                'full_cycle_amount' => (string) $l->full_cycle_amount, 'additional_amount' => (string) $l->additional_amount,
                'source' => $l->source->value, 'quantity' => $l->quantity, 'note' => $l->note, 'show_on_slip' => $l->show_on_slip,
            ])->values()->all(),
            'gross_pay' => (string) $s->gross_pay, 'total_deductions' => (string) $s->total_deductions, 'net_pay' => (string) $s->net_pay,
            'net_rounding_adjustment' => (string) $s->net_rounding_adjustment, 'employer_total' => (string) $s->employer_total,
            'statutory' => $st === null ? null : $st->only([
                'epf_part', 'epf_wage', 'epf_employee', 'epf_employer', 'socso_category', 'socso_wage', 'socso_employee', 'socso_employer',
                'skbbk_employee', 'eis_eligible', 'eis_wage', 'eis_employee', 'eis_employer', 'hrdc_levy', 'pcb_method', 'pcb_normal',
                'pcb_additional', 'zakat_payroll', 'zakat_external', 'pcb_net', 'cp38',
            ]),
            'ytd' => $st?->ytd_snapshot,
            'warnings' => $s->warnings ?? [], 'has_errors' => $s->has_errors,
            'pcb_override_amount' => $s->pcb_override_amount, 'lwp_override_days' => $s->lwp_override_days,
            'trace' => $withTrace ? ['calc' => $s->calc_trace, 'pcb_inputs' => $st?->pcb_inputs] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dryRun(SlipResult $r): array
    {
        return [
            'lines' => array_map(fn ($l) => ['code' => $l->component->code, 'name' => $l->component->nameMs, 'kind' => $l->component->kind->value,
                'full_cycle' => $l->fullCycle, 'amount' => $l->amount], $r->lines),
            'gross' => $r->gross, 'deductions' => $r->deductions, 'net' => $r->net, 'employer_total' => $r->employerTotal, 'warnings' => $r->warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function additional(AdditionalSalary $a): array
    {
        return [
            'id' => $a->id, 'number' => $a->number, 'employee_id' => $a->employee_id, 'employee' => $a->employee?->full_name,
            'salary_component_id' => $a->salary_component_id, 'component' => $a->component?->code, 'kind' => $a->kind->value,
            'period' => $a->period?->format('Y-m'), 'from_period' => $a->from_period?->format('Y-m'), 'to_period' => $a->to_period?->format('Y-m'),
            'amount' => (string) $a->amount, 'mode' => $a->mode->value, 'prorate' => $a->prorate, 'source_type' => $a->source_type->value,
            'source_label' => $a->source_type->label(), 'source_ref' => $a->source_ref, 'reason' => $a->reason, 'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'applications' => $a->applications()->with('slip:id,number,status')->get()->map(fn ($x) => ['period' => $x->period->format('Y-m'),
                'amount' => (string) $x->amount, 'slip_id' => $x->salary_slip_id, 'slip_number' => $x->slip?->number])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function arrear(Arrear $a): array
    {
        return [
            'id' => $a->id, 'number' => $a->number, 'employee_id' => $a->employee_id, 'employee' => $a->employee?->full_name,
            'salary_assignment_id' => $a->salary_assignment_id, 'from_period' => $a->from_period->format('Y-m'), 'to_period' => $a->to_period->format('Y-m'),
            'payout_period' => $a->payout_period->format('Y-m'), 'total_amount' => (string) $a->total_amount, 'status' => $a->status->value,
            'lines' => $a->lines->map(fn ($l) => ['period' => $l->period->format('Y-m'), 'salary_component_id' => $l->salary_component_id, 'component' => self::componentCode($l->salary_component_id),
                'entitled_amount' => (string) $l->entitled_amount, 'paid_amount' => (string) $l->paid_amount, 'difference' => (string) $l->difference,
                'included' => $l->included, 'basis' => $l->basis])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function correction(PayrollCorrection $c): array
    {
        return [
            'id' => $c->id, 'number' => $c->number, 'employee_id' => $c->employee_id, 'employee' => $c->employee?->full_name, 'salary_slip_id' => $c->salary_slip_id, 'slip_number' => $c->slip?->number,
            'type' => $c->type->value, 'type_label' => $c->type->label(), 'lwp_days_reversed' => $c->lwp_days_reversed,
            'payout_period' => $c->payout_period->format('Y-m'), 'reason' => $c->reason, 'status' => $c->status->value,
            'lines' => $c->lines->map(fn ($l) => ['salary_component_id' => $l->salary_component_id, 'component' => self::componentCode($l->salary_component_id), 'amount' => (string) $l->amount, 'basis' => $l->basis])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function withholding(SalaryWithholding $w): array
    {
        return [
            'id' => $w->id, 'number' => $w->number, 'employee_id' => $w->employee_id, 'employee' => $w->employee?->full_name,
            'from_period' => $w->from_period->format('Y-m'), 'cycles' => $w->cycles, 'reason' => $w->reason, 'status' => $w->status->value,
            'cycle_rows' => $w->cycleRows->map(fn (SalaryWithholdingCycle $c) => ['id' => $c->id, 'period' => $c->period->format('Y-m'),
                'salary_slip_id' => $c->salary_slip_id, 'released_at' => $c->released_at?->format('Y-m-d H:i'), 'release_reason' => $c->release_reason])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function declaration(TaxDeclaration $d): array
    {
        return $d->only(['id', 'number', 'employee_id', 'tax_year', 'is_correction', 'consumed_by_slip_id', 'prev_employer_name', 'prev_employer_e_no',
            'prev_gross_normal', 'prev_gross_additional', 'prev_epf', 'prev_pcb', 'prev_zakat', 'prev_cp38', 'prev_lp', 'zakat_amount',
            'zakat_authority', 'receipt_no', 'remarks']) + [
                'type' => $d->type->value, 'type_label' => $d->type->label(), 'period' => $d->period->format('Y-m'), 'status' => $d->status->value,
                'prev_from' => $d->prev_from?->toDateString(), 'prev_to' => $d->prev_to?->toDateString(), 'receipt_date' => $d->receipt_date?->toDateString(),
                'employee' => $d->employee?->full_name,
                'lines' => $d->lines->map(fn ($l) => ['relief_code' => $l->relief_code, 'amount' => (string) $l->amount, 'reason' => $l->reason])->all(),
            ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function file(PayrollFile $f): array
    {
        return [
            'id' => $f->id, 'kind' => $f->kind->value, 'kind_label' => $f->kind->label(), 'period' => $f->period->format('Y-m'),
            'payroll_run_id' => $f->payroll_run_id, 'file_name' => $f->file_name, 'record_count' => $f->record_count,
            'total_amount' => (string) $f->total_amount, 'status' => $f->status, 'format_code' => $f->format_code,
            'format_unverified' => $f->format_unverified, 'generated_at' => $f->generated_at?->format('Y-m-d H:i'),
            'download_url' => '/payroll/files/'.$f->id.'/download',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rateSet(StatutoryRateSet $s): array
    {
        return [
            'id' => $s->id, 'scheme' => $s->scheme->value, 'scheme_label' => $s->scheme->label(), 'code' => $s->code, 'name' => $s->name,
            'effective_from' => $s->effective_from->toDateString(), 'effective_to' => $s->effective_to?->toDateString(),
            'status' => $s->status->value, 'status_label' => $s->status->label(), 'is_placeholder' => $s->is_placeholder,
            'source_url' => $s->source_url, 'source_ref' => $s->source_ref, 'notes' => $s->notes,
            'fixture_passed_at' => $s->fixture_passed_at?->format('Y-m-d H:i'), 'verified_at' => $s->verified_at?->format('Y-m-d H:i'),
            'created_by' => $s->created_by, 'verified_by' => $s->verified_by,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ea(EaForm $f): array
    {
        return ['id' => $f->id, 'employee_id' => $f->employee_id, 'tax_year' => $f->tax_year, 'serial_no' => $f->serial_no,
            'status' => $f->status->value, 'status_label' => $f->status->label(), 'data' => $f->data, 'published_at' => $f->published_at?->format('Y-m-d H:i')];
    }
}
