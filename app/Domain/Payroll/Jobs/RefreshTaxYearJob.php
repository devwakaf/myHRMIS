<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Jobs;

use App\Domain\Payroll\Actions\RefreshTaxYear;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued YTD rebuild after a run is submitted or cancelled (spec-04 §5.3).
 * Stamps the run's ytd_refreshed_at when given.
 */
final class RefreshTaxYearJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $employeeIds
     */
    public function __construct(
        public readonly int $organisationId,
        public readonly array $employeeIds,
        public readonly int $year,
        public readonly ?int $runId = null,
    ) {
        $this->onQueue('payroll');
    }

    public function handle(RefreshTaxYear $refresh): void
    {
        OrganisationContext::run($this->organisationId, function () use ($refresh): void {
            foreach ($this->employeeIds as $id) {
                $refresh->handle($id, $this->year);
            }
            if ($this->runId !== null) {
                PayrollRun::query()->whereKey($this->runId)->update(['ytd_refreshed_at' => now()]);
            }
        });
    }
}
