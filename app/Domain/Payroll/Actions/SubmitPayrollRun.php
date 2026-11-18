<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Leave\Enums\PayoutStatus;
use App\Domain\Leave\Events\LeaveEncashmentPayoutChanged;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Enums\GenerationStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Enums\ProcessingState;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Jobs\RefreshTaxYearJob;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\AdditionalSalaryApplication;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Models\TaxDeclaration;
use App\Domain\Payroll\Services\SlipPublisher;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Statutory\Enums\RateSetStatus;
use App\Domain\Statutory\Models\StatutoryRateSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Hantar & Kunci" (spec-04 §5.3): Payroll Approver locks the run. Segregation
 * of duties, completeness checks and the unverified-rate-set switch; slips are
 * submitted, OT claims marked posted, then YTD refresh and slip e-mails follow
 * after commit. From here PayrollLockQuery reports the dates as locked.
 */
final class SubmitPayrollRun
{
    public function __construct(
        private readonly AttendanceForPayroll $attendance,
        private readonly SlipPublisher $publisher,
    ) {}

    public function handle(PayrollRun $run, User $actor, bool $acknowledgeUnverified = false): PayrollRun
    {
        if (! $actor->is_super_admin && in_array($actor->id, array_filter([$run->prepared_by, $run->generated_by]), true)) {
            throw new PayrollRuleViolation('payroll.errors.sod_submit');
        }
        if ($run->status !== PayrollRunStatus::SlipsGenerated) {
            throw new PayrollRuleViolation('payroll.errors.submit_status');
        }
        if ($run->processing_state !== ProcessingState::Idle) {
            throw new PayrollRuleViolation('payroll.errors.run_busy');
        }

        $blocking = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->where('included', true)
            ->whereIn('generation_status', [GenerationStatus::Failed->value, GenerationStatus::Pending->value])->count();
        if ($blocking > 0) {
            throw new PayrollRuleViolation('payroll.errors.submit_failures', ['count' => $blocking]);
        }
        if (SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', DocumentStatus::Draft->value)->where('has_errors', true)->exists()) {
            throw new PayrollRuleViolation('payroll.errors.submit_slip_errors');
        }

        $settings = PayrollSettings::current($run->organisation_id);
        $unverified = StatutoryRateSet::query()->whereIn('id', $run->rate_set_ids ?? [])
            ->where('status', '!=', RateSetStatus::Verified->value)->pluck('code')->all();
        if ($unverified !== []) {
            if ($settings->block_unverified_rates) {
                throw new PayrollRuleViolation('payroll.errors.unverified_rates', ['codes' => implode(', ', $unverified)]);
            }
            if (! $acknowledgeUnverified) {
                throw new PayrollRuleViolation('payroll.errors.unverified_ack_required', ['codes' => implode(', ', $unverified)]);
            }
        }

        $encashments = [];
        $slips = DB::transaction(function () use ($run, $actor, $unverified, &$encashments) {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== PayrollRunStatus::SlipsGenerated) {
                throw new PayrollRuleViolation('payroll.errors.submit_status');
            }

            $slips = SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', DocumentStatus::Draft->value)->get();
            foreach ($slips as $slip) {
                $slip->transitionTo(DocumentStatus::Submitted, $actor);
            }

            $slipIds = $slips->pluck('id');
            $applied = AdditionalSalaryApplication::query()->whereIn('salary_slip_id', $slipIds)->pluck('additional_salary_id');
            $sources = AdditionalSalary::query()->whereIn('id', $applied)->get(['id', 'source_type', 'source_id']);
            $claimIds = $sources->where('source_type', AdditionalSource::OvertimeClaim)->pluck('source_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $encashments = $sources->where('source_type', AdditionalSource::LeaveEncashment)->pluck('source_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            if ($claimIds !== []) {
                $this->attendance->markOvertimePosted($claimIds, $run->id);
            }

            // Declarations of these months become locked (spec-04 §2.8).
            foreach ($slips as $slip) {
                TaxDeclaration::query()->where('employee_id', $slip->employee_id)->where('status', DocumentStatus::Submitted->value)
                    ->whereDate('period', '<=', $slip->period->toDateString())->whereYear('period', $slip->period->year)
                    ->whereNull('consumed_by_slip_id')->update(['consumed_by_slip_id' => $slip->id]);
            }

            $locked->forceFill(['unverified_acknowledged' => $unverified !== []])->save();
            $locked->transitionTo(PayrollRunStatus::Submitted, $actor);
            if ($unverified !== []) {
                activity('payroll')->performedOn($locked)->causedBy($actor)->event('unverified_acknowledged')
                    ->withProperties(['rate_sets' => $unverified])->log('unverified_acknowledged');
            }

            return $slips;
        });

        foreach ($encashments as $id) {
            LeaveEncashmentPayoutChanged::dispatch($id, PayoutStatus::InPayroll);
        }

        RefreshTaxYearJob::dispatch($run->organisation_id, $slips->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all(), $run->period->year, $run->id);

        if ($settings->email_slips && $settings->publish_slips_on === 'submit') {
            $this->publisher->notify($slips);
        }

        return $run->refresh();
    }
}
