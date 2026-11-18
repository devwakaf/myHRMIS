<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Enums\ProcessingState;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\SalarySlipStatutory;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Support\Facades\DB;

/**
 * Batch `finally` (spec-04 §5.2): recompute run totals, move the run to
 * Slip Dijana when at least one slip exists, and set processing back to idle.
 */
final class FinalisePayrollGeneration
{
    public function handle(int $runId, bool $crashed = false): void
    {
        $organisationId = (int) PayrollRun::acrossOrganisations()->whereKey($runId)->value('organisation_id');
        if ($organisationId === 0) {
            return;
        }

        OrganisationContext::run($organisationId, function () use ($runId, $crashed): void {
            DB::transaction(function () use ($runId, $crashed): void {
                $run = PayrollRun::query()->lockForUpdate()->findOrFail($runId);
                $this->recomputeTotals($run);

                $hasSlips = SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', '!=', DocumentStatus::Cancelled->value)->exists();
                if ($run->status === PayrollRunStatus::Draft && $hasSlips) {
                    $run->transitionTo(PayrollRunStatus::SlipsGenerated, null);
                }

                $run->forceFill([
                    'processing_state' => $crashed ? ProcessingState::Failed : ProcessingState::Idle,
                    'processing_finished_at' => now(),
                    'processing_error' => $crashed ? (string) __('payroll.errors.batch_crashed') : null,
                ])->save();
            });
        });
    }

    public function recomputeTotals(PayrollRun $run): void
    {
        $slips = SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', '!=', DocumentStatus::Cancelled->value)->get();
        $gross = $deductions = $net = $employer = '0';
        foreach ($slips as $slip) {
            $gross = Num::add($gross, (string) $slip->gross_pay);
            $deductions = Num::add($deductions, (string) $slip->total_deductions);
            $net = Num::add($net, (string) $slip->net_pay);
            $employer = Num::add($employer, (string) $slip->employer_total);
        }

        $setIds = [];
        foreach (SalarySlipStatutory::query()->whereIn('salary_slip_id', $slips->pluck('id'))->get() as $st) {
            $setIds = [...$setIds, ...$st->rateSetIds()];
        }

        $run->forceFill([
            'employee_count' => $slips->count(),
            'gross_total' => Num::money($gross),
            'deduction_total' => Num::money($deductions),
            'net_total' => Num::money($net),
            'employer_total' => Num::money($employer),
            'withheld_count' => $slips->where('payment_status', PaymentStatus::Withheld)->count(),
            'rate_set_ids' => array_values(array_unique($setIds)),
        ])->save();
    }
}
