<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Core\Enums\DailyRateBasis;
use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\AdditionalKind;
use App\Domain\Payroll\Enums\AdditionalMode;
use App\Domain\Payroll\Enums\AdditionalSource;
use App\Domain\Payroll\Exceptions\PayrollRuleViolation;
use App\Domain\Payroll\Models\AdditionalSalary;
use App\Domain\Payroll\Models\Arrear;
use App\Domain\Payroll\Models\ArrearLine;
use App\Domain\Payroll\Models\SalaryAssignment;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Services\FullCycleEvaluator;
use App\Domain\Payroll\Services\PaymentDaysCalculator;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Num;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tunggakan Gaji (spec-04 §4.10) — increment model: for each submitted slip in
 * the range, every later assignment segment adds (full_new − full_prev) × the
 * fraction of the month it covers; never rebuilt from absolute segments.
 * Submit creates one Additional Salary per payout component (pcb additional).
 */
final class ArrearActions
{
    public const DEFAULT_COMPONENT = 'TUNGGAKAN_GAJI';

    public function __construct(
        private readonly FullCycleEvaluator $evaluator,
        private readonly PaymentDaysCalculator $days,
        private readonly AdditionalSalaryActions $additional,
    ) {}

    public function compute(SalaryAssignment $assignment, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $payout, ?string $notes = null): Arrear
    {
        if ($assignment->status !== DocumentStatus::Submitted) {
            throw new PayrollRuleViolation('payroll.errors.arrear_assignment_not_submitted');
        }
        $employee = Employee::query()->findOrFail($assignment->employee_id);

        return DB::transaction(function () use ($assignment, $employee, $from, $to, $payout, $notes): Arrear {
            $arrear = new Arrear([
                'employee_id' => $employee->id, 'salary_assignment_id' => $assignment->id,
                'from_period' => $from->startOfMonth()->toDateString(), 'to_period' => $to->startOfMonth()->toDateString(),
                'payout_period' => $payout->startOfMonth()->toDateString(), 'notes' => $notes,
            ]);
            $arrear->save();

            $total = '0';
            foreach ($this->lines($employee, $from->startOfMonth(), $to->startOfMonth()) as $line) {
                ArrearLine::query()->create(['arrear_id' => $arrear->id] + $line);
                if ($line['included']) {
                    $total = Num::add($total, $line['difference']);
                }
            }
            $arrear->forceFill(['total_amount' => Num::money($total), 'computed_at' => now()])->save();

            return $arrear;
        });
    }

    public function submit(Arrear $arrear, User $actor): Arrear
    {
        return DB::transaction(function () use ($arrear, $actor): Arrear {
            $arrear->transitionTo(DocumentStatus::Submitted, $actor);
            $byTarget = [];
            foreach ($arrear->lines()->where('included', true)->get() as $line) {
                $component = SalaryComponent::query()->findOrFail($line->salary_component_id);
                $target = $component->arrear_component_id ?? SalaryComponent::byCode(self::DEFAULT_COMPONENT)->id
                    ?? throw new PayrollRuleViolation('payroll.errors.arrear_component_missing');
                $byTarget[$target] = Num::add($byTarget[$target] ?? '0', (string) $line->difference);
            }
            foreach ($byTarget as $componentId => $amount) {
                if (! Num::isPositive($amount)) {
                    continue;
                }
                $this->additional->createSystem([
                    'employee_id' => $arrear->employee_id, 'salary_component_id' => $componentId, 'kind' => AdditionalKind::OneOff,
                    'period' => $arrear->payout_period->toDateString(), 'amount' => Num::money($amount), 'mode' => AdditionalMode::Add,
                    'source_type' => AdditionalSource::Arrear, 'source_id' => $arrear->id, 'source_ref' => 'arrear:'.$arrear->id.':'.$componentId,
                    'reason' => (string) __('payroll.messages.arrear_reason', ['number' => $arrear->number]),
                ]);
            }

            return $arrear;
        });
    }

    public function cancel(Arrear $arrear, User $actor, string $reason): Arrear
    {
        return DB::transaction(function () use ($arrear, $actor, $reason): Arrear {
            if ($arrear->isDraft()) {
                $arrear->delete();

                return $arrear;
            }
            $rows = AdditionalSalary::query()->where('source_type', AdditionalSource::Arrear->value)->where('source_id', $arrear->id)
                ->where('status', DocumentStatus::Submitted->value)->get();
            foreach ($rows as $row) {
                $this->additional->cancel($row, $actor, $reason, viaSource: true);
            }
            $arrear->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

            return $arrear;
        });
    }

    /**
     * @return list<array{period: string, salary_component_id: int, entitled_amount: string, paid_amount: string, difference: string, old_salary_slip_id: int, included: bool, basis: string}>
     */
    public function lines(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $slips = SalarySlip::query()->where('employee_id', $employee->id)->where('status', DocumentStatus::Submitted->value)
            ->whereDate('period', '>=', $from->toDateString())->whereDate('period', '<=', $to->toDateString())
            ->with('lines')->orderBy('period')->get();

        foreach ($slips as $slip) {
            $frozen = SalaryAssignment::query()->findOrFail($slip->salary_assignment_id);
            $segments = SalaryAssignment::query()->where('employee_id', $employee->id)->where('status', DocumentStatus::Submitted->value)
                ->whereDate('effective_from', '>', $frozen->effective_from->toDateString())
                ->whereDate('effective_from', '<=', $slip->period_end->toDateString())
                ->orderBy('effective_from')->get();
            if ($segments->isEmpty()) {
                continue;
            }

            $previous = $this->evaluator->values($employee, $frozen, $slip->period);
            $differences = [];
            $basis = [];
            foreach ($segments as $segment) {
                $current = $this->evaluator->values($employee, $segment, $slip->period);
                $fraction = $this->fraction($employee, $slip, $segment->effective_from);
                foreach ($current as $code => $value) {
                    if (! $value['arrear_eligible']) {
                        continue;
                    }
                    $increment = Num::sub($value['amount'], $previous[$code]['amount'] ?? '0');
                    $differences[$code] = Num::add($differences[$code] ?? '0', Num::mul($increment, $fraction));
                    $basis[$code][] = $segment->effective_from->format('d/m/Y').': '.Num::money($increment).' × '.Num::trim(Num::fixed($fraction, 6));
                }
                $previous = $current;
            }

            foreach ($differences as $code => $difference) {
                $componentId = (int) ($previous[$code]['component_id'] ?? 0);
                $paid = (string) ($slip->lines->firstWhere('code', $code)->amount ?? '0.00');
                $diff = Num::money($difference);
                $out[] = [
                    'period' => $slip->period->toDateString(),
                    'salary_component_id' => $componentId,
                    'entitled_amount' => Num::money(Num::add($paid, $diff)),
                    'paid_amount' => Num::money($paid),
                    'difference' => $diff,
                    'old_salary_slip_id' => $slip->id,
                    'included' => Num::isPositive($diff),
                    'basis' => implode('; ', $basis[$code] ?? []),
                ];
            }
        }

        return $out;
    }

    /**
     * fraction(d) = clamp((divisor − unpaid_before_d − unpaid_from_d) / divisor, 0, 1); equals the
     * slip's own payment fraction when d is on or before the period start (§4.10).
     */
    private function fraction(Employee $employee, SalarySlip $slip, CarbonImmutable $d): string
    {
        if (! $d->greaterThan($slip->period_start)) {
            return (string) $slip->payment_fraction;
        }

        $divisor = (string) $slip->divisor;
        $before = $slip->rate_basis === DailyRateBasis::CalendarDays->value
            ? (string) $slip->period_start->diffInDays($d)
            : ($this->days->workingDays($employee, $slip->period_start, $d->subDay()) ?? '0');

        $from = '0';
        $trace = $slip->calc_trace ?? [];
        foreach ((array) ($trace['unpaid_items'] ?? []) as $item) {
            if (is_array($item) && isset($item['date'], $item['unpaid_portion']) && ! CarbonImmutable::parse((string) $item['date'])->lessThan($d)) {
                $from = Num::add($from, (string) $item['unpaid_portion']);
            }
        }
        foreach ((array) ($trace['absent_dates'] ?? []) as $item) {
            if (is_array($item) && isset($item['date'], $item['fraction']) && ! CarbonImmutable::parse((string) $item['date'])->lessThan($d)) {
                $from = Num::add($from, (string) $item['fraction']);
            }
        }
        if ($slip->actual_end->lessThan($slip->period_end)) {
            $start = $slip->actual_end->addDay()->max($d);
            $from = Num::add($from, $slip->rate_basis === DailyRateBasis::CalendarDays->value
                ? (string) ($start->diffInDays($slip->period_end) + 1)
                : ($this->days->workingDays($employee, $start, $slip->period_end) ?? '0'));
        }

        return Num::clamp(Num::div(Num::sub(Num::sub($divisor, $before), $from), $divisor), '0', '1');
    }
}
