<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Enums\PayMode;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAggregateCap;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveBlockList;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Support\DomainValidation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cuti configuration masters (spec-02 §2.1–§2.3, §2.12–§2.14) with their rules.
 */
final class LeaveConfigActions
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function saveType(?LeaveType $type, array $data): LeaveType
    {
        $basis = EntitlementBasis::tryFrom((string) ($data['entitlement_basis'] ?? $type?->entitlement_basis->value));
        $pay = PayMode::tryFrom((string) ($data['pay_mode'] ?? $type?->pay_mode->value));
        $fraction = (string) ($data['paid_fraction'] ?? ($pay === PayMode::Unpaid ? '0' : ($pay === PayMode::Paid ? '1' : ($type !== null ? (string) $type->paid_fraction : '0.5'))));
        $data['paid_fraction'] = $fraction;
        $errors = [];

        if ($basis === null) {
            $errors['entitlement_basis'] = __('validation.required', ['attribute' => __('leave.fields.entitlement_basis')]);
        }
        if ($pay === null) {
            $errors['pay_mode'] = __('validation.required', ['attribute' => __('leave.fields.pay_mode')]);
        }

        $noBalance = in_array($basis, [EntitlementBasis::Unlimited, EntitlementBasis::PerEvent], true);
        if ($noBalance && (! empty($data['allow_carry_forward']) || ! empty($data['allow_encashment']) || ! empty($data['allow_negative']))) {
            $errors['entitlement_basis'] = __('leave.errors.type_no_balance_options');
        }
        if ($basis === EntitlementBasis::Earned && (empty($data['accrual_frequency']) || empty($data['accrual_timing']))) {
            $errors['accrual_frequency'] = __('leave.errors.type_earned_needs_accrual');
        }
        if ($pay !== null && ! $this->fractionMatches($pay, $fraction)) {
            $errors['paid_fraction'] = __('leave.errors.type_fraction_mismatch');
        }
        if ($pay === PayMode::Unpaid && in_array($basis, [EntitlementBasis::PerPeriod, EntitlementBasis::Earned], true)) {
            $errors['pay_mode'] = __('leave.errors.type_unpaid_balance');
        }
        $code = strtoupper(trim((string) ($data['code'] ?? $type?->code)));
        $duplicate = LeaveType::query()->where('code', $code)->when($type, fn ($q) => $q->whereKeyNot($type->id))->exists();
        if ($duplicate) {
            $errors['code'] = __('leave.errors.type_code_taken');
        }

        if ($type !== null) {
            if ($type->is_system && $code !== $type->code) {
                $errors['code'] = __('leave.errors.type_code_locked');
            }
            $used = LeaveMovement::query()->where('leave_type_id', $type->id)->exists();
            if ($used && ($basis !== $type->entitlement_basis || $pay !== $type->pay_mode)) {
                $errors['entitlement_basis'] = __('leave.errors.type_basis_locked');
            }
        }
        DomainValidation::throwIf($errors);

        $data['code'] = $code;
        unset($data['is_system']);
        if ($type === null) {
            return LeaveType::query()->create($data);
        }
        $type->update($data);

        return $type;
    }

    /**
     * @param  array{name: string, from_date: string, to_date: string, notes?: string|null}  $data
     */
    public function savePeriod(?LeavePeriod $period, array $data): LeavePeriod
    {
        $from = CarbonImmutable::parse($data['from_date']);
        $to = CarbonImmutable::parse($data['to_date']);
        $span = $to->lessThan($from) ? 0 : (int) $from->diffInDays($to) + 1;
        if ($span < 28 || $span > 366) {
            throw DomainValidation::withMessages(['to_date' => __('leave.errors.period_span')]);
        }
        $overlap = LeavePeriod::query()->when($period, fn ($q) => $q->whereKeyNot($period->id))
            ->whereDate('from_date', '<=', $to->toDateString())->whereDate('to_date', '>=', $from->toDateString())->exists();
        if ($overlap) {
            throw DomainValidation::withMessages(['from_date' => __('leave.errors.period_overlap')]);
        }
        if ($period !== null && LeaveAllocation::query()->where('leave_period_id', $period->id)->exists()
            && ($from->toDateString() !== $period->from_date->toDateString() || $to->toDateString() !== $period->to_date->toDateString())) {
            throw DomainValidation::withMessages(['from_date' => __('leave.errors.period_in_use')]);
        }

        if ($period === null) {
            return LeavePeriod::query()->create($data);
        }
        $period->update($data);

        return $period;
    }

    public function deletePeriod(LeavePeriod $period): void
    {
        if (LeaveAllocation::query()->where('leave_period_id', $period->id)->exists()) {
            throw new LeaveRuleViolation('leave.errors.period_in_use');
        }
        $period->delete();
    }

    /**
     * @param  array{name: string, description?: string|null, is_active?: bool, rows: list<array{leave_type_id: int, annual_days?: string|int|null, uses_service_tiers?: bool, tier_basis?: string|null, override_max_carry_forward_days?: string|null, tiers?: list<array{min_service_months: int, days: string|int}>}>}  $data
     */
    public function savePolicy(?LeavePolicy $policy, array $data): LeavePolicy
    {
        $errors = [];
        $seen = [];
        foreach ($data['rows'] as $i => $row) {
            $type = LeaveType::query()->find($row['leave_type_id']);
            if ($type === null || ! in_array($type->entitlement_basis, [EntitlementBasis::PerPeriod, EntitlementBasis::Earned], true)) {
                $errors["rows.$i.leave_type_id"] = __('leave.errors.policy_row_type');
            }
            if (in_array($row['leave_type_id'], $seen, true)) {
                $errors["rows.$i.leave_type_id"] = __('leave.errors.policy_row_duplicate');
            }
            $seen[] = $row['leave_type_id'];
            if (! empty($row['uses_service_tiers'])) {
                $months = array_map(fn ($t) => (int) $t['min_service_months'], $row['tiers'] ?? []);
                if (! in_array(0, $months, true)) {
                    $errors["rows.$i.tiers"] = __('leave.errors.policy_tier_zero');
                }
                if (count($months) !== count(array_unique($months))) {
                    $errors["rows.$i.tiers"] = __('leave.errors.policy_tier_duplicate');
                }
            }
        }
        $duplicateName = LeavePolicy::query()->where('name', $data['name'])->when($policy, fn ($q) => $q->whereKeyNot($policy->id))->exists();
        if ($duplicateName) {
            $errors['name'] = __('leave.errors.policy_name_taken');
        }
        DomainValidation::throwIf($errors);

        return DB::transaction(function () use ($policy, $data): LeavePolicy {
            $values = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => $data['is_active'] ?? true];
            if ($policy === null) {
                $policy = LeavePolicy::query()->create($values);
            } else {
                $policy->update($values);
            }

            $keep = [];
            foreach ($data['rows'] as $row) {
                $model = $policy->rows()->updateOrCreate(['leave_type_id' => $row['leave_type_id']], [
                    'annual_days' => Dec::norm((string) ($row['annual_days'] ?? '0')),
                    'uses_service_tiers' => (bool) ($row['uses_service_tiers'] ?? false),
                    'tier_basis' => $row['tier_basis'] ?? 'prorata_by_anniversary',
                    'override_max_carry_forward_days' => $row['override_max_carry_forward_days'] ?? null,
                ]);
                $keep[] = $model->id;
                $model->tiers()->delete();
                foreach (($row['uses_service_tiers'] ?? false) ? ($row['tiers'] ?? []) : [] as $tier) {
                    $model->tiers()->create(['min_service_months' => (int) $tier['min_service_months'], 'days' => Dec::norm((string) $tier['days'])]);
                }
            }

            $removed = $policy->rows()->whereNotIn('id', $keep)->pluck('id');
            if ($removed->isNotEmpty()) {
                // Rows referenced by allocations keep a null reference (frozen amounts stay in the ledger).
                $policy->rows()->whereIn('id', $removed)->delete();
            }

            return $policy->load('rows.tiers');
        });
    }

    /**
     * @param  array{name: string, cap_days: string|int, is_active?: bool, leave_type_ids: list<int>}  $data
     */
    public function saveCap(?LeaveAggregateCap $cap, array $data): LeaveAggregateCap
    {
        if (count($data['leave_type_ids']) < 2) {
            throw DomainValidation::withMessages(['leave_type_ids' => __('leave.errors.cap_needs_types')]);
        }

        return DB::transaction(function () use ($cap, $data): LeaveAggregateCap {
            $values = ['name' => $data['name'], 'cap_days' => Dec::norm((string) $data['cap_days']), 'is_active' => $data['is_active'] ?? true];
            $cap = $cap === null ? LeaveAggregateCap::query()->create($values) : tap($cap)->update($values);
            $cap->types()->sync($data['leave_type_ids']);

            return $cap;
        });
    }

    /**
     * @param  array{name: string, applies_to_all_departments?: bool, leave_type_id?: int|null, is_active?: bool, dates: list<array{date: string, reason?: string|null}>, department_ids?: list<int>, exempt_user_ids?: list<int>}  $data
     */
    public function saveBlockList(?LeaveBlockList $list, array $data): LeaveBlockList
    {
        $all = (bool) ($data['applies_to_all_departments'] ?? true);
        if (! $all && empty($data['department_ids'])) {
            throw DomainValidation::withMessages(['department_ids' => __('leave.errors.block_list_departments')]);
        }

        return DB::transaction(function () use ($list, $data, $all): LeaveBlockList {
            $values = ['name' => $data['name'], 'applies_to_all_departments' => $all, 'leave_type_id' => $data['leave_type_id'] ?? null, 'is_active' => $data['is_active'] ?? true];
            $list = $list === null ? LeaveBlockList::query()->create($values) : tap($list)->update($values);

            $list->dates()->delete();
            $dates = [];
            foreach ($data['dates'] as $row) {
                $dates[CarbonImmutable::parse($row['date'])->toDateString()] = $row['reason'] ?? null;
            }
            foreach ($dates as $date => $reason) {
                $list->dates()->create(['date' => $date, 'reason' => $reason]);
            }
            $list->departments()->sync($all ? [] : ($data['department_ids'] ?? []));
            $list->exemptUsers()->sync($data['exempt_user_ids'] ?? []);

            return $list->load(['dates', 'departments', 'exemptUsers']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): LeaveSettings
    {
        if (! empty($data['default_leave_policy_id']) && ! LeavePolicy::query()->whereKey($data['default_leave_policy_id'])->exists()) {
            throw DomainValidation::withMessages(['default_leave_policy_id' => __('leave.errors.invalid_policy')]);
        }
        $settings = LeaveSettings::current();
        $settings->update($data);

        return $settings;
    }

    private function fractionMatches(PayMode $pay, string $fraction): bool
    {
        return match ($pay) {
            PayMode::Paid => Dec::cmp($fraction, '1') === 0,
            PayMode::Unpaid => Dec::cmp($fraction, '0') === 0,
            PayMode::PartiallyPaid => Dec::cmp($fraction, '0') > 0 && Dec::cmp($fraction, '1') < 0,
        };
    }
}
