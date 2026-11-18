<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryWithholding;
use App\Domain\Payroll\Models\SalaryWithholdingCycle;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tahan Gaji (spec-04 §2.7, §5.4): create (PO), submit, cancel; "Lepaskan" per
 * cycle (PA or FIN) turns the withheld slip back to unpaid so the supplementary
 * bank file can pay it.
 */
final class WithholdingActions
{
    /**
     * @param  array{employee_id: int, from_period: string, cycles?: int|null, reason: string}  $data
     */
    public function create(array $data, User $actor, bool $submit = true): SalaryWithholding
    {
        $employee = Employee::query()->find($data['employee_id']);
        if ($employee === null) {
            throw DomainValidation::withMessages(['employee_id' => __('payroll.errors.invalid_employee')]);
        }
        $from = CarbonImmutable::parse($data['from_period'])->startOfMonth();
        $cycles = isset($data['cycles']) ? max(1, (int) $data['cycles']) : null;

        $overlap = SalaryWithholding::query()->where('employee_id', $employee->id)->where('status', '!=', DocumentStatus::Cancelled->value)->get()
            ->first(fn (SalaryWithholding $w) => $w->covers($from) || ($cycles !== null && collect(range(0, $cycles - 1))->contains(fn (int $i) => $w->covers($from->addMonths($i)))));
        if ($overlap !== null) {
            throw new PayrollRuleViolation('payroll.errors.withholding_overlap', ['number' => $overlap->number]);
        }

        return DB::transaction(function () use ($employee, $from, $cycles, $data, $actor, $submit): SalaryWithholding {
            $row = new SalaryWithholding(['employee_id' => $employee->id, 'from_period' => $from->toDateString(), 'cycles' => $cycles, 'reason' => $data['reason']]);
            $row->save();
            if ($submit) {
                $row->transitionTo(DocumentStatus::Submitted, $actor);
            }

            return $row;
        });
    }

    public function cancel(SalaryWithholding $row, User $actor, string $reason): SalaryWithholding
    {
        if ($row->isDraft()) {
            $row->delete();

            return $row;
        }
        $row->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

        return $row;
    }

    /** "Lepaskan" one withheld month. */
    public function release(SalaryWithholdingCycle $cycle, User $actor, string $reason): SalaryWithholdingCycle
    {
        if ($cycle->released_at !== null) {
            throw new PayrollRuleViolation('payroll.errors.already_released');
        }

        return DB::transaction(function () use ($cycle, $actor, $reason): SalaryWithholdingCycle {
            $cycle->forceFill(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => $reason])->save();
            $slip = $cycle->salary_slip_id === null ? null : SalarySlip::query()->find($cycle->salary_slip_id);
            if ($slip !== null && $slip->payment_status === PaymentStatus::Withheld) {
                $slip->forceFill(['payment_status' => PaymentStatus::Unpaid, 'released_at' => now(), 'released_by' => $actor->id])->save();
            }

            return $cycle;
        });
    }
}
