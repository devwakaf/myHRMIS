<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Enums\AdditionalKind;
use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Enums\ComponentKind;
use App\Domain\Payroll\Enums\CorrectionType;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\PayrollCorrection;
use App\Domain\Payroll\Models\PayrollCorrectionLine;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pembetulan Payroll against one submitted slip (spec-04 §4.11):
 * - lwp_reversal: refund per prorated earning = halfUp(full × days ÷ the slip's divisor);
 * - amount: signed lines; negatives become PELARASAN_POTONGAN deductions.
 * Submit posts system Additional Salaries in the payout month.
 */
final class CorrectionActions
{
    public const EARNING_ADJUSTMENT = 'PELARASAN_PENDAPATAN';

    public const DEDUCTION_ADJUSTMENT = 'PELARASAN_POTONGAN';

    public function __construct(private readonly AdditionalSalaryActions $additional) {}

    /**
     * @param  list<array{salary_component_id: int, amount: string, basis?: string|null}>  $lines
     */
    public function create(SalarySlip $slip, CorrectionType $type, CarbonImmutable $payout, string $reason, ?string $days = null, array $lines = []): PayrollCorrection
    {
        if ($slip->status !== DocumentStatus::Submitted) {
            throw new PayrollRuleViolation('payroll.errors.correction_slip_not_submitted');
        }
        if (trim($reason) === '') {
            throw DomainValidation::withMessages(['reason' => __('payroll.errors.reason_required')]);
        }

        if ($type === CorrectionType::LwpReversal) {
            if ($days === null || ! is_numeric($days) || ! Num::isPositive($days)) {
                throw DomainValidation::withMessages(['lwp_days_reversed' => __('payroll.errors.invalid_days')]);
            }
            $already = (string) PayrollCorrection::query()->where('salary_slip_id', $slip->id)
                ->where('status', '!=', DocumentStatus::Cancelled->value)->sum('lwp_days_reversed');
            if (Num::cmp(Num::add($already, $days), (string) $slip->lwp_days) > 0) {
                throw new PayrollRuleViolation('payroll.errors.correction_days_exceed', ['days' => Num::trim((string) $slip->lwp_days)]);
            }
            $lines = $this->refundLines($slip, $days);
        } elseif ($lines === []) {
            throw DomainValidation::withMessages(['lines' => __('payroll.errors.lines_required')]);
        }

        return DB::transaction(function () use ($slip, $type, $payout, $reason, $days, $lines): PayrollCorrection {
            $correction = new PayrollCorrection([
                'employee_id' => $slip->employee_id, 'salary_slip_id' => $slip->id, 'type' => $type,
                'lwp_days_reversed' => $type === CorrectionType::LwpReversal ? Num::fixed((string) $days, 3) : null,
                'payout_period' => $payout->startOfMonth()->toDateString(), 'reason' => $reason,
            ]);
            $correction->save();
            foreach ($lines as $line) {
                PayrollCorrectionLine::query()->create([
                    'payroll_correction_id' => $correction->id, 'salary_component_id' => $line['salary_component_id'],
                    'amount' => Num::money($line['amount']), 'basis' => $line['basis'] ?? null,
                ]);
            }

            return $correction;
        });
    }

    /**
     * @return list<array{salary_component_id: int, amount: string, basis: string}>
     */
    public function refundLines(SalarySlip $slip, string $days): array
    {
        $out = [];
        foreach ($slip->lines()->where('kind', ComponentKind::Earning->value)->get() as $line) {
            $component = SalaryComponent::query()->find($line->salary_component_id);
            if ($component === null || ! $component->prorate || ! Num::isPositive((string) $line->full_cycle_amount)) {
                continue;
            }
            $refund = (string) Rounding::halfUp(Num::div(Num::mul((string) $line->full_cycle_amount, $days), (string) $slip->divisor), 2);
            $out[] = [
                'salary_component_id' => $component->id,
                'amount' => $refund,
                'basis' => Num::money((string) $line->full_cycle_amount).' × '.Num::trim($days).' ÷ '.Num::trim((string) $slip->divisor),
            ];
        }

        return $out;
    }

    public function submit(PayrollCorrection $correction, User $actor): PayrollCorrection
    {
        return DB::transaction(function () use ($correction, $actor): PayrollCorrection {
            $correction->transitionTo(DocumentStatus::Submitted, $actor);
            $earning = SalaryComponent::byCode(self::EARNING_ADJUSTMENT);
            $deduction = SalaryComponent::byCode(self::DEDUCTION_ADJUSTMENT);

            $byComponent = [];
            foreach ($correction->lines as $line) {
                $amount = (string) $line->amount;
                if (Num::isNegative($amount)) {
                    $target = $deduction->id ?? throw new PayrollRuleViolation('payroll.errors.adjustment_component_missing', ['code' => self::DEDUCTION_ADJUSTMENT]);
                    $amount = Num::neg($amount);
                } elseif ($correction->type === CorrectionType::LwpReversal) {
                    $target = $earning->id ?? throw new PayrollRuleViolation('payroll.errors.adjustment_component_missing', ['code' => self::EARNING_ADJUSTMENT]);
                } else {
                    $target = $line->salary_component_id;
                }
                $byComponent[$target] = Num::add($byComponent[$target] ?? '0', $amount);
            }

            foreach ($byComponent as $componentId => $amount) {
                if (! Num::isPositive($amount)) {
                    continue;
                }
                $this->additional->createSystem([
                    'employee_id' => $correction->employee_id, 'salary_component_id' => $componentId, 'kind' => AdditionalKind::OneOff,
                    'period' => $correction->payout_period->toDateString(), 'amount' => Num::money($amount), 'mode' => AdditionalMode::Add,
                    'source_type' => AdditionalSource::Correction, 'source_id' => $correction->id,
                    'source_ref' => 'correction:'.$correction->id.':'.$componentId,
                    'reason' => (string) __('payroll.messages.correction_reason', ['number' => $correction->number]),
                ]);
            }

            return $correction;
        });
    }

    public function cancel(PayrollCorrection $correction, User $actor, string $reason): PayrollCorrection
    {
        return DB::transaction(function () use ($correction, $actor, $reason): PayrollCorrection {
            if ($correction->isDraft()) {
                $correction->delete();

                return $correction;
            }
            $rows = AdditionalSalary::query()->where('source_type', AdditionalSource::Correction->value)->where('source_id', $correction->id)
                ->where('status', DocumentStatus::Submitted->value)->get();
            foreach ($rows as $row) {
                $this->additional->cancel($row, $actor, $reason, viaSource: true);
            }
            $correction->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

            return $correction;
        });
    }
}
