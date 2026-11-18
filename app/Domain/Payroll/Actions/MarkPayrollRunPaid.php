<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Leave\Enums\PayoutStatus;
use App\Domain\Leave\Events\LeaveEncashmentPayoutChanged;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Enums\PaymentStatus;
use App\Domain\Payroll\Enums\PayrollRunStatus;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\AdditionalSalaryApplication;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollSettings;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Services\SlipPublisher;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Tandakan Dibayar" (spec-04 §5.4): Finance records payment; every unpaid,
 * non-withheld slip becomes Dibayar and the run becomes Dibayar. Also the
 * per-slip "paid" for a released withheld salary.
 */
final class MarkPayrollRunPaid
{
    public function __construct(private readonly SlipPublisher $publisher) {}

    public function handle(PayrollRun $run, User $actor, CarbonImmutable $paidOn, ?string $reference = null): PayrollRun
    {
        if ($run->status !== PayrollRunStatus::Submitted) {
            throw new PayrollRuleViolation('payroll.errors.mark_paid_status');
        }
        $settings = PayrollSettings::current($run->organisation_id);
        if (! $actor->is_super_admin && $run->submitted_by === $actor->id && ! $settings->allow_submitter_mark_paid) {
            throw new PayrollRuleViolation('payroll.errors.sod_mark_paid');
        }

        $paid = DB::transaction(function () use ($run, $actor, $paidOn, $reference) {
            $slips = SalarySlip::query()->where('payroll_run_id', $run->id)->where('status', DocumentStatus::Submitted->value)
                ->where('payment_status', PaymentStatus::Unpaid->value)->get();
            foreach ($slips as $slip) {
                $slip->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_at' => $paidOn->startOfDay()])->save();
            }
            $run->forceFill(['paid_at' => $paidOn->startOfDay(), 'paid_by' => $actor->id, 'payment_reference' => $reference])->save();
            $run->transitionTo(PayrollRunStatus::Paid, $actor);

            return $slips;
        });

        $this->encashmentsPaid($paid->pluck('id')->all());
        if ($settings->email_slips && $settings->publish_slips_on === 'paid') {
            $this->publisher->notify($paid);
        }

        return $run->refresh();
    }

    /** A released withheld salary paid through the supplementary bank file. */
    public function markSlipPaid(SalarySlip $slip, CarbonImmutable $paidOn): SalarySlip
    {
        if ($slip->status !== DocumentStatus::Submitted || $slip->payment_status !== PaymentStatus::Unpaid) {
            throw new PayrollRuleViolation('payroll.errors.slip_not_payable');
        }
        $slip->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_at' => $paidOn->startOfDay()])->save();
        $this->encashmentsPaid([$slip->id]);

        return $slip;
    }

    /**
     * @param  list<int>  $slipIds
     */
    private function encashmentsPaid(array $slipIds): void
    {
        $applied = AdditionalSalaryApplication::query()->whereIn('salary_slip_id', $slipIds)->pluck('additional_salary_id');
        $ids = AdditionalSalary::query()->whereIn('id', $applied)->where('source_type', AdditionalSource::LeaveEncashment->value)
            ->pluck('source_id')->filter()->unique();
        foreach ($ids as $id) {
            LeaveEncashmentPayoutChanged::dispatch((int) $id, PayoutStatus::Paid);
        }
    }
}
