<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Jobs;

use App\Domain\Payroll\Actions\GenerateSalarySlip;
use App\Domain\Payroll\Enums\GenerationStatus;
use App\Domain\Payroll\Enums\ProcessingState;
use App\Domain\Payroll\Exceptions\PayrollCalculationException;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainError;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One employee's slip in a run (spec-04 §5.2). Errors are isolated: a domain
 * error is recorded on the run employee with its BM message, anything else is
 * recorded generically and reported; the job itself never fails the batch.
 */
final class GenerateSalarySlipJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $organisationId,
        public readonly int $runId,
        public readonly int $runEmployeeId,
    ) {
        $this->onQueue('payroll');
    }

    public function handle(GenerateSalarySlip $generate): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        OrganisationContext::run($this->organisationId, function () use ($generate): void {
            $run = PayrollRun::query()->find($this->runId);
            $row = PayrollRunEmployee::query()->find($this->runEmployeeId);
            if ($run === null || $row === null || ! $row->included) {
                return;
            }
            if ($run->processing_state === ProcessingState::Queued) {
                PayrollRun::query()->whereKey($run->id)->update(['processing_state' => ProcessingState::Running->value]);
            }

            try {
                $generate->handle($run, $row);
            } catch (DomainError $e) {
                $this->fail_($row, $e instanceof PayrollCalculationException ? $e->errorCode() : $e->translationKey, $e->userMessage());
            } catch (Throwable $e) {
                report($e);
                $this->fail_($row, 'unexpected', (string) __('payroll.errors.unexpected'));
            }
        });
    }

    private function fail_(PayrollRunEmployee $row, string $code, string $message): void
    {
        $row->refresh();
        $row->forceFill([
            'generation_status' => GenerationStatus::Failed,
            'error_code' => mb_substr($code, 0, 80),
            'error_message' => $message,
            'attempts' => $row->attempts + 1,
        ])->save();
    }
}
