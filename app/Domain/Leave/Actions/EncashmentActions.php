<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Enums\DailyRateBasis;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\DailyRatePolicy;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Leave\Contracts\AdditionalPayPort;
use App\Domain\Leave\Contracts\CompensationQuery;
use App\Domain\Leave\Enums\EncashmentRateBasis;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Enums\PayoutStatus;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Services\LeaveBalance;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Services\LotPool;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Money\Rounding;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gantian Tunai Cuti (spec-02 §3.15): eligible days, daily rate per basis
 * (scale 6), amount half-up to sen; submit debits soonest-expiry lots and hands
 * the amount to Payroll as Additional Salary (§5.3).
 */
final class EncashmentActions
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeaveBalance $balance,
        private readonly CompensationQuery $compensation,
        private readonly AdditionalPayPort $additionalPay,
        private readonly HolidayResolver $holidays,
    ) {}

    /** Days that may be encashed from an allocation on a date (§3.15 step 1). */
    public function eligibleDays(LeaveAllocation $allocation, CarbonImmutable $date, ?int $excludeEncashmentId = null): string
    {
        $type = $allocation->leaveType;
        $pool = LotPool::load($allocation->employee_id, $allocation->leave_type_id, null, $allocation->id);
        $available = $pool->availableOn($date);

        $employee = Employee::query()->findOrFail($allocation->employee_id);
        $reserved = $this->balance->reserved($employee, $type, $date);
        $eligible = Dec::sub(Dec::sub($available, $reserved), (string) $type->encashment_reserve_days);

        if ($type->max_encashable_days !== null) {
            $already = $this->alreadyEncashed($allocation, $excludeEncashmentId);
            $eligible = Dec::min($eligible, Dec::sub((string) $type->max_encashable_days, $already));
        }

        return Dec::max($eligible, '0');
    }

    /**
     * Daily rate and amount (§3.15 steps 3–5).
     *
     * @return array{daily_rate: string, amount: string, orp_daily_rate: string, below_orp: bool}
     */
    public function rate(Employee $employee, string $monthlyBasic, string $days, EncashmentRateBasis $basis, CarbonImmutable $date): array
    {
        $orp = (string) Rounding::halfUp(bcdiv($monthlyBasic, DailyRatePolicy::ordinaryRateDivisor(), 12), 6);

        if ($basis === EncashmentRateBasis::Orp26) {
            $daily = $orp;
        } else {
            $policy = DailyRatePolicy::for(Organisation::query()->findOrFail($employee->organisation_id));
            $divisor = match ($policy->basis) {
                DailyRateBasis::FixedDivisor => $policy->divisor,
                DailyRateBasis::CalendarDays => (string) $date->daysInMonth,
                DailyRateBasis::WorkingDays => $this->holidays->workingDaysBetween($employee, $date->startOfMonth(), $date->endOfMonth()->startOfDay()),
            };
            if (bccomp($divisor, '0', 6) <= 0) {
                throw new LeaveRuleViolation('leave.errors.no_working_days_in_month');
            }
            $daily = (string) Rounding::halfUp(bcdiv($monthlyBasic, $divisor, 12), 6);
        }

        return [
            'daily_rate' => $daily,
            'amount' => (string) Rounding::halfUp(bcmul($daily, $days, 12), 2),
            'orp_daily_rate' => $orp,
            'below_orp' => bccomp($daily, $orp, 6) < 0,
        ];
    }

    /**
     * @param  array{leave_allocation_id: int, encashment_date: string, days: string|int, rate_basis?: string|null, monthly_basic?: string|null, purpose?: string|null, remarks?: string|null}  $data
     * @return array{encashment: LeaveEncashment, warnings: list<string>}
     */
    public function saveDraft(?LeaveEncashment $encashment, array $data): array
    {
        $allocation = LeaveAllocation::query()->live()->with('leaveType')->find($data['leave_allocation_id']);
        if ($allocation === null) {
            throw DomainValidation::withMessages(['leave_allocation_id' => __('leave.errors.invalid_allocation')]);
        }
        $type = $allocation->leaveType;
        if (! $type->allow_encashment) {
            throw DomainValidation::withMessages(['leave_allocation_id' => __('leave.errors.not_encashable')]);
        }

        $employee = Employee::query()->findOrFail($allocation->employee_id);
        $date = CarbonImmutable::parse($data['encashment_date']);
        $days = Dec::toStep((string) $data['days'], $type->rounding_step === null ? null : (string) $type->rounding_step);
        $eligible = $this->eligibleDays($allocation, $date, $encashment?->id);

        $errors = [];
        if (! Dec::isPositive($days)) {
            $errors['days'] = __('leave.errors.days_positive');
        } elseif (Dec::cmp($days, $eligible) > 0) {
            $errors['days'] = __('leave.errors.encash_exceeds', ['days' => Dec::short($eligible)]);
        }

        $basic = $this->compensation->monthlyBasic($employee, $date);
        $manual = $basic === null;
        $basicValue = $basic?->toDecimalString() ?? (isset($data['monthly_basic']) && is_numeric($data['monthly_basic']) ? (string) $data['monthly_basic'] : null);
        if ($basicValue === null || bccomp($basicValue, '0', 2) <= 0) {
            $errors['monthly_basic'] = __('leave.errors.monthly_basic_required');
        }
        DomainValidation::throwIf($errors);
        /** @var string $basicValue */
        $basis = EncashmentRateBasis::tryFrom((string) ($data['rate_basis'] ?? '')) ?? LeaveSettings::current($employee->organisation_id)->encashment_rate_basis;
        $rate = $this->rate($employee, $basicValue, $days, $basis, $date);

        $values = [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'leave_allocation_id' => $allocation->id,
            'encashment_date' => $date->toDateString(),
            'eligible_days' => $eligible,
            'days' => $days,
            'rate_basis' => $basis,
            'monthly_basic' => (string) Rounding::halfUp($basicValue, 2),
            'basic_entered_manually' => $manual,
            'daily_rate' => $rate['daily_rate'],
            'amount' => $rate['amount'],
            'purpose' => $data['purpose'] ?? 'other',
            'remarks' => $data['remarks'] ?? null,
        ];

        if ($encashment === null) {
            $encashment = LeaveEncashment::query()->create($values);
        } else {
            $encashment->update($values);
        }

        $warnings = [];
        if ($basis === EncashmentRateBasis::OrgDailyRate && $rate['below_orp']) {
            $warnings[] = (string) __('leave.warnings.encash_below_orp', ['rate' => $rate['orp_daily_rate']]);
        }

        return ['encashment' => $encashment, 'warnings' => $warnings];
    }

    public function submit(LeaveEncashment $encashment, User $actor): LeaveEncashment
    {
        return DB::transaction(function () use ($encashment, $actor): LeaveEncashment {
            $allocation = LeaveAllocation::query()->live()->lockForUpdate()->with('leaveType')->findOrFail($encashment->leave_allocation_id);
            $eligible = $this->eligibleDays($allocation, $encashment->encashment_date, $encashment->id);
            if (Dec::cmp((string) $encashment->days, $eligible) > 0) {
                throw new LeaveRuleViolation('leave.errors.encash_exceeds', ['days' => Dec::short($eligible)]);
            }

            $encashment->transitionTo(DocumentStatus::Submitted, $actor);
            $today = LeaveLedger::today();
            $pool = LotPool::load($allocation->employee_id, $allocation->leave_type_id, null, $allocation->id);
            $plan = $pool->consume([['date' => $encashment->encashment_date, 'portion' => (string) $encashment->days]], allowReclaim: false);
            if (Dec::isPositive($plan->shortfall())) {
                throw new LeaveRuleViolation('leave.errors.encash_exceeds', ['days' => Dec::short($plan->consumed())]);
            }
            foreach ($plan->perLot as $item) {
                $lot = LeaveMovement::query()->findOrFail($item['lot_id']);
                $this->ledger->debit($lot, MovementKind::Encashment, $item['days'], $encashment->encashment_date, $encashment->encashment_date, $today, $encashment);
            }

            $id = $this->additionalPay->createForEncashment($encashment);
            $encashment->forceFill(['additional_salary_id' => $id])->save();

            return $encashment;
        });
    }

    public function cancel(LeaveEncashment $encashment, User $actor, string $reason): LeaveEncashment
    {
        return DB::transaction(function () use ($encashment, $actor, $reason): LeaveEncashment {
            if ($encashment->payout_status !== PayoutStatus::AwaitingPayroll) {
                throw new LeaveRuleViolation('leave.errors.encash_in_payroll');
            }
            $wasSubmitted = $encashment->status === DocumentStatus::Submitted;
            if ($wasSubmitted) {
                $this->additionalPay->cancelForEncashment($encashment);
            }
            $encashment->transitionTo(DocumentStatus::Cancelled, $actor, $reason);
            if ($wasSubmitted) {
                $this->ledger->reverseAll(LeaveMovement::query()->fromSource($encashment)->where('kind', MovementKind::Encashment->value), LeaveLedger::today(), $encashment, $reason);
            }

            return $encashment;
        });
    }

    private function alreadyEncashed(LeaveAllocation $allocation, ?int $excludeEncashmentId): string
    {
        $query = LeaveEncashment::query()->where('leave_allocation_id', $allocation->id)
            ->where('status', DocumentStatus::Submitted->value)
            ->when($excludeEncashmentId !== null, fn ($q) => $q->whereKeyNot($excludeEncashmentId));

        return Dec::norm((string) $query->sum('days'));
    }
}
