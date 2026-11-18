<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\AdditionalKind;
use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Exceptions\PayrollCalculationException;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use Carbon\CarbonImmutable;

/**
 * Posts approved OT claims as system-submitted Additional Salaries (spec-04 §4.5).
 * Payroll never calculates OT; amounts come frozen from Attendance. Idempotent
 * through source_ref `overtime_claim:{claimId}:{componentId}`.
 */
final class OvertimePosting
{
    public function __construct(private readonly AttendanceForPayroll $attendance) {}

    /**
     * @return list<int> claim ids posted for this employee
     */
    public function postFor(PayrollRun $run, Employee $employee, CarbonImmutable $actualStart, CarbonImmutable $actualEnd): array
    {
        $claimIds = [];
        foreach ($this->attendance->overtimePayLines($employee, $run->period) as $line) {
            $component = $line->salaryComponentId === null ? null : SalaryComponent::query()->find($line->salaryComponentId);
            if ($component === null || ! $component->is_active || $component->kind !== ComponentKind::Earning) {
                throw PayrollCalculationException::of('invalid_ot_component', ['claim' => $line->claimNumber]);
            }
            foreach ($line->dates as $date) {
                $d = CarbonImmutable::parse($date);
                if ($d->lessThan($employee->date_of_joining) || ($employee->relieving_date !== null && $d->greaterThan($employee->relieving_date)) || $d->greaterThan($actualEnd)) {
                    throw PayrollCalculationException::of('ot_date_outside_employment', ['claim' => $line->claimNumber, 'date' => $d->format('d/m/Y')]);
                }
            }

            $ref = 'overtime_claim:'.$line->claimId.':'.$component->id;
            $note = (string) __('payroll.messages.ot_line_note', ['number' => $line->claimNumber, 'month' => $line->claimMonth->format('m/Y')]);
            $existing = AdditionalSalary::query()->where('source_ref', $ref)->where('status', '!=', DocumentStatus::Cancelled->value)->first();

            if ($existing === null) {
                $row = new AdditionalSalary([
                    'employee_id' => $employee->id,
                    'salary_component_id' => $component->id,
                    'kind' => AdditionalKind::OneOff,
                    'period' => $run->period->toDateString(),
                    'amount' => $line->amount->toDecimalString(),
                    'mode' => AdditionalMode::Add,
                    'prorate' => false,
                    'quantity' => Num::fixed(Num::div((string) $line->minutes, '60'), 3),
                    'source_type' => AdditionalSource::OvertimeClaim,
                    'source_id' => $line->claimId,
                    'source_ref' => $ref,
                    'payroll_run_id' => $run->id,
                    'note' => $note,
                    'reason' => $note,
                ]);
                $row->forceFill(['status' => DocumentStatus::Submitted, 'submitted_at' => now()])->save();
            } elseif ($existing->payroll_run_id !== $run->id) {
                $existing->forceFill(['payroll_run_id' => $run->id])->save();
            }
            $claimIds[] = $line->claimId;
        }

        return array_values(array_unique($claimIds));
    }

    /**
     * Run cancelled or slips discarded: cancel the run's OT postings that no
     * submitted slip consumed, and give the claims back to Attendance.
     */
    public function release(PayrollRun $run): void
    {
        $rows = AdditionalSalary::query()->where('payroll_run_id', $run->id)
            ->where('source_type', AdditionalSource::OvertimeClaim->value)
            ->where('status', DocumentStatus::Submitted->value)->get();
        foreach ($rows as $row) {
            $consumed = $row->applications()->whereHas('slip', fn ($q) => $q->where('status', DocumentStatus::Submitted->value))->exists();
            if ($consumed) {
                continue;
            }
            $row->applications()->delete();
            $row->transitionTo(DocumentStatus::Cancelled, null, (string) __('payroll.messages.ot_released', ['run' => $run->number]));
        }

        $this->attendance->releaseOvertime($run->id);
    }
}
