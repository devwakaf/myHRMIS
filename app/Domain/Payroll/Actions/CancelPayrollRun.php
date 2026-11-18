<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Leave\Enums\PayoutStatus;
use App\Domain\Leave\Events\LeaveEncashmentPayoutChanged;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Enums\GenerationStatus;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollFileKind;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Enums\ProcessingState;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Jobs\RefreshTaxYearJob;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\AdditionalSalaryApplication;
use App\Domain\Payroll\Models\PayrollFile;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalaryWithholdingCycle;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Services\OvertimePosting;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batal / Buang Slip (spec-04 §5.5). Draft runs are deleted; a generated run can
 * discard its slips (back to Draf) or be cancelled; a submitted run is cancelled
 * only when nothing downstream depends on it. Paid runs are never cancelled.
 */
final class CancelPayrollRun
{
    public function __construct(private readonly OvertimePosting $overtime) {}

    /** "Buang Slip": slips_generated → draft, drafts deleted, keys freed. */
    public function discardSlips(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::SlipsGenerated) {
            throw new PayrollRuleViolation('payroll.errors.discard_status');
        }
        $this->assertIdle($run);

        DB::transaction(function () use ($run, $actor): void {
            $this->deleteDraftSlips($run);
            PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->update([
                'active_period_key' => null, 'salary_slip_id' => null, 'generation_status' => GenerationStatus::Pending->value,
                'error_code' => null, 'error_message' => null, 'warnings' => null,
            ]);
            $this->overtime->release($run);
            app(FinalisePayrollGeneration::class)->recomputeTotals($run);
            $run->transitionTo(PayrollRunStatus::Draft, $actor);
        });

        return $run->refresh();
    }

    public function delete(PayrollRun $run): void
    {
        if ($run->status !== PayrollRunStatus::Draft) {
            throw new PayrollRuleViolation('payroll.errors.delete_status');
        }
        $this->assertIdle($run);
        DB::transaction(function () use ($run): void {
            $this->deleteDraftSlips($run);
            $this->overtime->release($run);
            PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->delete();
            $run->delete();
        });
    }

    public function cancel(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        if ($run->status === PayrollRunStatus::Paid) {
            throw new PayrollRuleViolation('payroll.errors.cancel_paid');
        }
        if ($run->status === PayrollRunStatus::Cancelled) {
            throw new PayrollRuleViolation('payroll.errors.cancel_status');
        }
        $this->assertIdle($run);

        if ($run->status === PayrollRunStatus::Submitted) {
            $this->assertCancellable($run);
        }

        $wasSubmitted = $run->status === PayrollRunStatus::Submitted;
        $encashments = [];
        $employeeIds = [];

        DB::transaction(function () use ($run, $actor, $reason, $wasSubmitted, &$encashments, &$employeeIds): void {
            $slips = SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', '!=', DocumentStatus::Cancelled->value)->get();
            $employeeIds = $slips->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $applied = AdditionalSalaryApplication::query()->whereIn('salary_slip_id', $slips->pluck('id'))->pluck('additional_salary_id');
            $encashments = AdditionalSalary::query()->whereIn('id', $applied)->where('source_type', AdditionalSource::LeaveEncashment->value)
                ->pluck('source_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

            if ($wasSubmitted) {
                foreach ($slips as $slip) {
                    SalaryWithholdingCycle::query()->where('salary_slip_id', $slip->id)->update(['salary_slip_id' => null]);
                    AdditionalSalaryApplication::query()->where('salary_slip_id', $slip->id)->delete();
                    TaxDeclaration::query()->where('consumed_by_slip_id', $slip->id)->update(['consumed_by_slip_id' => null]);
                    $slip->forceFill(['active_period_key' => null, 'salary_withholding_cycle_id' => null])->save();
                    $slip->transitionTo(DocumentStatus::Cancelled, $actor, $reason);
                }
            } else {
                $this->deleteDraftSlips($run);
            }

            PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->update(['active_period_key' => null]);
            $this->overtime->release($run);
            $run->transitionTo(PayrollRunStatus::Cancelled, $actor, $reason);
        });

        if ($wasSubmitted) {
            foreach ($encashments as $id) {
                LeaveEncashmentPayoutChanged::dispatch($id, PayoutStatus::AwaitingPayroll);
            }
            RefreshTaxYearJob::dispatch($run->organisation_id, $employeeIds, $run->period->year, $run->id);
        }

        return $run->refresh();
    }

    private function assertCancellable(PayrollRun $run): void
    {
        if (SalarySlip::query()->where('payroll_run_id', $run->id)->where('payment_status', PaymentStatus::Paid->value)->exists()) {
            throw new PayrollRuleViolation('payroll.errors.cancel_has_paid');
        }

        $submittedFile = PayrollFile::query()->whereDate('period', $run->period->toDateString())
            ->whereIn('kind', [PayrollFileKind::Kwsp->value, PayrollFileKind::Perkeso->value, PayrollFileKind::Cp39->value])
            ->where('status', 'submitted')->exists();
        if ($submittedFile) {
            throw new PayrollRuleViolation('payroll.errors.cancel_file_submitted');
        }

        $employeeIds = SalarySlip::query()->where('payroll_run_id', $run->id)->pluck('employee_id');
        $later = SalarySlip::query()->whereIn('employee_id', $employeeIds)->where('status', DocumentStatus::Submitted->value)
            ->whereDate('period', '>', $run->period->toDateString())->value('number');
        if ($later !== null) {
            throw new PayrollRuleViolation('payroll.errors.cancel_later_submitted', ['number' => (string) $later]);
        }
    }

    private function assertIdle(PayrollRun $run): void
    {
        if (! in_array($run->processing_state, [ProcessingState::Idle, ProcessingState::Failed], true)) {
            throw new PayrollRuleViolation('payroll.errors.run_busy');
        }
    }

    private function deleteDraftSlips(PayrollRun $run): void
    {
        $ids = SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', DocumentStatus::Draft->value)->pluck('id');
        SalaryWithholdingCycle::query()->whereIn('salary_slip_id', $ids)->update(['salary_slip_id' => null]);
        AdditionalSalaryApplication::query()->whereIn('salary_slip_id', $ids)->delete();
        foreach (SalarySlip::query()->whereIn('id', $ids)->get() as $slip) {
            $slip->delete();
        }
        PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->update(['salary_slip_id' => null]);
    }
}
