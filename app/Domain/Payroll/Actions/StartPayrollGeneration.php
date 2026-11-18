<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Enums\GenerationStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Enums\ProcessingState;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Jobs\GenerateSalarySlipJob;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Services\EligibleEmployees;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Jana Slip" (spec-04 §5.2): claims the run atomically (concurrent clicks get
 * "Run sedang diproses"), syncs run employees (claiming the per-month key; a
 * conflict marks that employee skipped) and dispatches one queued job per
 * employee in a batch with allowFailures and a `finally` that finalises the run.
 */
final class StartPayrollGeneration
{
    public function __construct(private readonly EligibleEmployees $eligible) {}

    /**
     * @param  list<int>|null  $employeeIds  regenerate only these (null = everyone in the run)
     */
    public function handle(PayrollRun $run, User $actor, ?array $employeeIds = null, bool $failedOnly = false): PayrollRun
    {
        $claimed = PayrollRun::query()->whereKey($run->id)
            ->whereIn('processing_state', [ProcessingState::Idle->value, ProcessingState::Failed->value])
            ->whereIn('status', [PayrollRunStatus::Draft->value, PayrollRunStatus::SlipsGenerated->value])
            ->update([
                'processing_state' => ProcessingState::Queued->value,
                'lock_version' => DB::raw('lock_version + 1'),
                'processing_started_at' => now(),
                'processing_finished_at' => null,
                'processing_error' => null,
                'generated_by' => $actor->id,
            ]);
        if ($claimed === 0) {
            throw new PayrollRuleViolation('payroll.errors.run_busy');
        }
        $run->refresh();

        try {
            if ($employeeIds === null && ! $failedOnly) {
                $this->syncEmployees($run);
            }

            $rows = PayrollRunEmployee::query()->where('payroll_run_id', $run->id)->where('included', true)
                ->whereNotNull('active_period_key')
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds ?? []))
                ->when($failedOnly, fn ($q) => $q->where('generation_status', GenerationStatus::Failed->value))
                ->get();
        } catch (Throwable $e) {
            PayrollRun::query()->whereKey($run->id)->update(['processing_state' => ProcessingState::Idle->value]);

            throw $e;
        }

        $jobs = $rows->map(fn (PayrollRunEmployee $row) => new GenerateSalarySlipJob($run->organisation_id, $run->id, $row->id))->values()->all();
        $runId = $run->id;

        if ($jobs === []) {
            app(FinalisePayrollGeneration::class)->handle($runId);

            return $run->refresh();
        }

        foreach ($rows as $row) {
            $row->forceFill(['generation_status' => GenerationStatus::Pending, 'error_code' => null, 'error_message' => null])->save();
        }

        $batch = Bus::batch($jobs)
            ->name('payroll-run:'.$run->number)
            ->allowFailures()
            ->onQueue('payroll')
            ->finally(static function (Batch $batch) use ($runId): void {
                app(FinalisePayrollGeneration::class)->handle($runId, $batch->failedJobs > 0 && $batch->processedJobs() === 0);
            })
            ->dispatch();

        PayrollRun::query()->whereKey($run->id)->update(['batch_id' => $batch->id]);

        return $run->refresh();
    }

    /** Upsert run employees from the eligible list (§5.1), claiming active_period_key. */
    private function syncEmployees(PayrollRun $run): void
    {
        $result = $this->eligible->for($run);

        foreach ($result['eligible'] as $employee) {
            $row = PayrollRunEmployee::query()->firstOrCreate(
                ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                ['included' => true, 'generation_status' => GenerationStatus::Pending],
            );
            if (! $row->included || $row->active_period_key !== null) {
                continue;
            }
            $this->claimKey($run, $row);
        }

        foreach ($result['no_assignment'] as $employee) {
            $row = PayrollRunEmployee::query()->firstOrCreate(
                ['payroll_run_id' => $run->id, 'employee_id' => $employee->id],
                ['included' => true],
            );
            $row->forceFill([
                'generation_status' => GenerationStatus::Skipped, 'error_code' => 'no_assignment',
                'error_message' => (string) __('payroll.errors.no_assignment_skipped'), 'active_period_key' => null,
            ])->save();
        }
    }

    private function claimKey(PayrollRun $run, PayrollRunEmployee $row): void
    {
        $key = $run->periodKey($row->employee_id);
        try {
            DB::transaction(fn () => $row->forceFill(['active_period_key' => $key])->save());
        } catch (QueryException) {
            $holder = PayrollRunEmployee::query()->where('active_period_key', $key)->first();
            $number = $holder === null ? '' : (string) PayrollRun::query()->whereKey($holder->payroll_run_id)->value('number');
            $row->forceFill([
                'active_period_key' => null,
                'generation_status' => GenerationStatus::Skipped,
                'error_code' => 'in_other_run',
                'error_message' => (string) __('payroll.errors.in_other_run', ['number' => $number]),
            ])->save();
        }
    }
}
