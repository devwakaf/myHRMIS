<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sufficiency test (spec-02 §3.4), used on submit and in the completion veto.
 * Must run inside a transaction: the employee's allocation rows of the type are
 * locked FOR UPDATE so two submissions cannot both pass. Reservation is FIFO by
 * submission time: other pending applications are simulated first.
 */
final class LeaveSufficiency
{
    public function __construct(private readonly AggregateCaps $caps) {}

    /**
     * @param  list<array{date: CarbonImmutable, portion: string}>  $days  deductible days of the application
     * @return array{ok: bool, error: string|null, params: array<string, string>, warnings: list<array{key: string, params: array<string, string>}>, shortfall: string, reserved: string}
     */
    public function check(Employee $employee, LeaveType $type, array $days, ?int $applicationId = null): array
    {
        $result = ['ok' => true, 'error' => null, 'params' => [], 'warnings' => [], 'shortfall' => '0', 'reserved' => '0'];

        if ($type->hasBalance()) {
            $this->lockAllocations($employee->id, $type->id);
            $pool = LotPool::load($employee->id, $type->id);
            $negativeBefore = $pool->negativeTotal();

            $othersUnmet = '0';
            $reserved = '0';
            foreach ($this->otherPending($employee->id, $type->id, $applicationId) as $other) {
                $otherDays = $other->days->filter(fn ($d) => Dec::isPositive($d->portion))
                    ->map(fn ($d) => ['date' => $d->date, 'portion' => (string) $d->portion])->values()->all();
                $reserved = Dec::add($reserved, (string) $other->total_days);
                $othersUnmet = Dec::add($othersUnmet, $pool->consume($otherDays)->shortfall());
            }

            $shortfall = $pool->consume($days)->shortfall();
            $result['shortfall'] = $shortfall;
            $result['reserved'] = $reserved;

            if (Dec::isPositive($shortfall)) {
                $negativeAfter = Dec::add($negativeBefore, $othersUnmet, $shortfall);
                if ($type->allow_negative && Dec::cmp($negativeAfter, (string) $type->max_negative_days) <= 0) {
                    $result['warnings'][] = ['key' => 'leave.warnings.negative_balance', 'params' => ['days' => Dec::short($shortfall)]];
                } else {
                    return array_merge($result, [
                        'ok' => false,
                        'error' => 'leave.errors.insufficient_balance',
                        'params' => ['short' => Dec::short($shortfall), 'reserved' => Dec::short($reserved)],
                    ]);
                }
            }
        }

        foreach ($this->capViolations($employee, $type, $days, $applicationId) as $violation) {
            return array_merge($result, ['ok' => false, 'error' => 'leave.errors.aggregate_cap', 'params' => $violation]);
        }

        return $result;
    }

    /**
     * @param  list<array{date: CarbonImmutable, portion: string}>  $days
     * @return list<array<string, string>>
     */
    public function capViolations(Employee $employee, LeaveType $type, array $days, ?int $applicationId = null): array
    {
        $out = [];
        $caps = $this->caps->capsFor($type->id);
        if ($caps->isEmpty() || $days === []) {
            return [];
        }

        $byPeriod = [];
        foreach ($days as $day) {
            $period = LeavePeriod::containing($day['date']);
            if ($period === null) {
                continue;
            }
            $byPeriod[$period->id] ??= ['period' => $period, 'days' => '0'];
            $byPeriod[$period->id]['days'] = Dec::add($byPeriod[$period->id]['days'], $day['portion']);
        }

        foreach ($caps as $cap) {
            foreach ($byPeriod as $entry) {
                $used = $this->caps->usage($employee->id, $cap, $entry['period'], $applicationId);
                if (Dec::cmp(Dec::add($used, $entry['days']), (string) $cap->cap_days) > 0) {
                    $out[] = [
                        'cap' => $cap->name,
                        'cap_days' => Dec::short((string) $cap->cap_days),
                        'used' => Dec::short($used),
                        'room' => Dec::short(Dec::max(Dec::sub((string) $cap->cap_days, $used), '0')),
                    ];
                }
            }
        }

        return $out;
    }

    public function lockAllocations(int $employeeId, int $leaveTypeId): void
    {
        LeaveAllocation::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * @return Collection<int, LeaveApplication>
     */
    private function otherPending(int $employeeId, int $leaveTypeId, ?int $applicationId): Collection
    {
        $others = LeaveApplication::query()
            ->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->where('status', ApprovalStatus::Pending->value)
            ->when($applicationId !== null, fn ($q) => $q->whereKeyNot($applicationId))
            ->with('days')
            ->orderBy('submitted_at')->orderBy('id')
            ->get();

        // Already pending (completion veto): only requests queued before this one keep priority.
        $self = $applicationId === null ? null : LeaveApplication::query()->find($applicationId);
        if ($self !== null && $self->status === ApprovalStatus::Pending && $self->submitted_at !== null) {
            $key = [$self->submitted_at->getTimestamp(), $self->id];
            $others = $others->filter(fn (LeaveApplication $o) => [$o->submitted_at?->getTimestamp() ?? 0, $o->id] < $key)->values();
        }

        return $others;
    }
}
