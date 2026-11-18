<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Payroll\Enums\DeclarationType;
use App\Domain\Payroll\Enums\EaFormStatus;
use App\Domain\Payroll\Enums\RemittanceScheme;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\EaForm;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\StatutoryRemittance;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Services\TaxYearCalculator;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Borang EA and PCB 2(II) data (spec-04 §6.4–§6.5). EA freezes this employer's
 * figures (submitted slips + same-employer opening; TP3 previous-employer income
 * excluded) per employee; draft → publish (PA) → visible in "Borang EA Saya";
 * regeneration after publish supersedes. PDF rendering belongs to the UI milestone.
 */
final class EaFormActions
{
    public function __construct(private readonly TaxYearCalculator $calculator) {}

    /**
     * @param  list<int>|null  $employeeIds
     * @return list<EaForm>
     */
    public function generate(int $year, ?array $employeeIds = null): array
    {
        $ids = SalarySlip::query()->where('status', DocumentStatus::Submitted->value)->whereYear('period', $year)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds ?? []))
            ->distinct()->pluck('employee_id')->map(fn ($id) => (int) $id)->all();

        $forms = [];
        foreach ($ids as $employeeId) {
            $forms[] = DB::transaction(fn () => $this->generateFor($employeeId, $year));
        }

        return $forms;
    }

    public function generateFor(int $employeeId, int $year): EaForm
    {
        $data = $this->data($employeeId, $year);
        $existing = EaForm::query()->where('employee_id', $employeeId)->where('tax_year', $year)
            ->where('status', '!=', EaFormStatus::Superseded->value)->first();

        if ($existing !== null && $existing->status === EaFormStatus::Draft) {
            $existing->forceFill(['data' => $data])->save();

            return $existing;
        }

        $serial = sprintf('EA-%d-%05d', $year, $employeeId);
        if ($existing !== null) {
            $existing->forceFill(['status' => EaFormStatus::Superseded])->save();
            $serial .= '-'.(EaForm::query()->where('employee_id', $employeeId)->where('tax_year', $year)->count() + 1);
            $data['supersedes'] = $existing->serial_no;
        }

        $form = new EaForm(['employee_id' => $employeeId, 'tax_year' => $year, 'serial_no' => $serial, 'data' => $data]);
        $form->save();

        return $form;
    }

    public function publish(EaForm $form, User $actor): EaForm
    {
        if ($form->status !== EaFormStatus::Draft) {
            throw new PayrollRuleViolation('payroll.errors.ea_not_draft');
        }
        $form->forceFill(['status' => EaFormStatus::Published, 'published_at' => now(), 'published_by' => $actor->id])->save();

        return $form;
    }

    /**
     * Frozen EA figures: this employer's slips + same-employer opening (TP3 excluded).
     *
     * @return array<string, mixed>
     */
    public function data(int $employeeId, int $year): array
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $org = Organisation::query()->findOrFail(OrganisationContext::id());
        $totals = $this->calculator->totals($employeeId, $year);

        // TaxYearCalculator includes TP3 (previous employer) in Y/K/X/Z; EA must not.
        $tp3 = TaxDeclaration::query()->where('employee_id', $employeeId)->where('tax_year', $year)
            ->where('status', DocumentStatus::Submitted->value)->where('type', DeclarationType::Tp3->value)->get();
        $minus = fn (string $value, string $column) => Num::money(Num::sub($value, Num::add(...$tp3->map(fn (TaxDeclaration $d) => (string) $d->getAttribute($column))->all())));

        $slips = SalarySlip::query()->where('employee_id', $employeeId)->where('status', DocumentStatus::Submitted->value)
            ->whereYear('period', $year)->orderBy('period')->get(['period', 'actual_start', 'actual_end']);
        $opening = SalaryAssignment::query()->where('employee_id', $employeeId)->where('status', DocumentStatus::Submitted->value)
            ->where('opening_year', $year)->first();

        return [
            'employer' => [
                'name' => $org->name, 'lhdn_employer_no' => $org->getAttribute('lhdn_employer_no'),
                'address' => trim(implode(', ', array_filter([$org->getAttribute('address_line1'), $org->getAttribute('address_line2'), $org->getAttribute('postcode'), $org->getAttribute('city')]))),
            ],
            'employee' => [
                'employee_no' => $employee->employee_no, 'full_name' => $employee->full_name, 'ic_no' => $employee->ic_no,
                'passport_no' => $employee->passport_no, 'tin' => $employee->taxProfile?->tin, 'designation' => Designation::query()->whereKey($employee->designation_id)->value('name'),
                'kwsp_no' => $employee->getAttribute('kwsp_no'), 'perkeso_no' => $employee->getAttribute('perkeso_no'),
            ],
            'employment' => [
                'from' => $slips->first()?->actual_start->toDateString() ?? $opening?->effective_from->toDateString(),
                'to' => $slips->last()?->actual_end->toDateString(),
                'joined_in_year' => $employee->date_of_joining->year === $year,
            ],
            'remuneration' => $totals->eaBoxes,
            'tax_exempt' => $totals->taxExemptUsed,
            'pcb' => $minus($totals->x, 'prev_pcb'),
            'cp38' => $minus($totals->cp38, 'prev_cp38'),
            'zakat' => $minus($totals->z, 'prev_zakat'),
            'epf_employee' => $minus($totals->k, 'prev_epf'),
            'socso_employee' => $totals->socsoEmployee,
            'eis_employee' => $totals->eisEmployee,
            'skbbk_employee' => $totals->skbbkEmployee,
            'gross' => Num::money(Num::add(...array_values($totals->eaBoxes) ?: ['0'])),
            'generated_at' => now()->toIso8601String(),
            'unverified_note' => 'EA 2026 box codes UNVERIFIED-STAT',
        ];
    }

    /**
     * PCB 2(II) data: monthly PCB and CP38 with remittance receipts (spec-04 §6.4).
     *
     * @return array{employee_id: int, year: int, months: list<array{period: string, pcb: string, cp38: string, receipt_no: string|null, paid_on: string|null}>, total_pcb: string, total_cp38: string}
     */
    public function pcb2ii(int $employeeId, int $year): array
    {
        $slips = SalarySlip::query()->where('employee_id', $employeeId)->where('status', DocumentStatus::Submitted->value)
            ->whereYear('period', $year)->with('statutory')->orderBy('period')->get();
        $remittances = StatutoryRemittance::query()->where('scheme', RemittanceScheme::Pcb->value)->whereYear('period', $year)->get()
            ->keyBy(fn (StatutoryRemittance $r) => $r->period->format('Y-m'));

        $months = [];
        $pcb = $cp38 = '0';
        foreach ($slips as $slip) {
            $r = $remittances->get($slip->period->format('Y-m'));
            $months[] = [
                'period' => $slip->period->format('Y-m'),
                'pcb' => (string) ($slip->statutory->pcb_net ?? '0.00'),
                'cp38' => (string) ($slip->statutory->cp38 ?? '0.00'),
                'receipt_no' => $r?->receipt_no,
                'paid_on' => $r?->paid_on->toDateString(),
            ];
            $pcb = Num::add($pcb, (string) ($slip->statutory->pcb_net ?? '0'));
            $cp38 = Num::add($cp38, (string) ($slip->statutory->cp38 ?? '0'));
        }

        return ['employee_id' => $employeeId, 'year' => $year, 'months' => $months, 'total_pcb' => Num::money($pcb), 'total_cp38' => Num::money($cp38)];
    }
}
