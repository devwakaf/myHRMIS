<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Console;

use App\Domain\Core\Models\Organisation;
use App\Domain\Leave\Contracts\AdditionalPayPort;
use App\Domain\Leave\Enums\PayoutStatus;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use Illuminate\Console\Command;

/**
 * php artisan payroll:backfill-encashments — on first deployment of M4, posts
 * every submitted encashment still awaiting payroll as an Additional Salary
 * (spec-04 §4.2). Idempotent.
 */
final class BackfillPendingEncashments extends Command
{
    protected $signature = 'payroll:backfill-encashments';

    protected $description = 'Post awaiting-payroll leave encashments as Additional Salaries';

    public function handle(AdditionalPayPort $port): int
    {
        $posted = 0;
        foreach (Organisation::query()->where('is_active', true)->pluck('id') as $orgId) {
            OrganisationContext::run((int) $orgId, function () use ($port, &$posted): void {
                $rows = LeaveEncashment::query()->where('status', DocumentStatus::Submitted->value)
                    ->where('payout_status', PayoutStatus::AwaitingPayroll->value)->whereNull('additional_salary_id')->get();
                foreach ($rows as $encashment) {
                    $id = $port->createForEncashment($encashment);
                    if ($id !== null) {
                        $encashment->forceFill(['additional_salary_id' => $id])->save();
                        $posted++;
                    }
                }
            });
        }
        $this->info("{$posted} encashment(s) posted.");

        return self::SUCCESS;
    }
}
