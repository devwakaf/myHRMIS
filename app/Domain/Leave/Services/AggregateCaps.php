<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Leave\Models\LeaveAggregateCap;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Had Gabungan Cuti (spec-02 §2.12). Usage within a leave period = deducted
 * days of approved and pending applications of member types dated in the period
 * (day rows are kept in step with the ledger, so this equals net application
 * debits + reserved days).
 */
final class AggregateCaps
{
    /**
     * @return Collection<int, LeaveAggregateCap>
     */
    public function capsFor(int $leaveTypeId): Collection
    {
        return LeaveAggregateCap::query()->where('is_active', true)
            ->whereHas('types', fn ($q) => $q->where('leave_types.id', $leaveTypeId))
            ->with('types')
            ->get();
    }

    public function usage(int $employeeId, LeaveAggregateCap $cap, LeavePeriod $period, ?int $excludeApplicationId = null): string
    {
        $typeIds = $cap->types->pluck('id')->all();
        if ($typeIds === []) {
            return '0';
        }

        $sum = DB::table('leave_application_days as d')
            ->join('leave_applications as a', 'a.id', '=', 'd.leave_application_id')
            ->where('a.employee_id', $employeeId)
            ->whereIn('a.leave_type_id', $typeIds)
            ->whereIn('a.status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->when($excludeApplicationId !== null, fn ($q) => $q->where('a.id', '!=', $excludeApplicationId))
            ->whereBetween('d.date', [$period->from_date->toDateString(), $period->to_date->toDateString()])
            ->sum('d.portion');

        return Dec::norm((string) $sum);
    }

    /**
     * Remaining room under every cap containing the type, for the period containing $date.
     *
     * @return list<array{cap_id: int, name: string, cap_days: string, used: string, room: string}>
     */
    public function roomOn(int $employeeId, int $leaveTypeId, CarbonInterface $date, ?int $excludeApplicationId = null): array
    {
        $period = LeavePeriod::containing($date);
        if ($period === null) {
            return [];
        }

        $out = [];
        foreach ($this->capsFor($leaveTypeId) as $cap) {
            $used = $this->usage($employeeId, $cap, $period, $excludeApplicationId);
            $out[] = [
                'cap_id' => $cap->id,
                'name' => $cap->name,
                'cap_days' => (string) $cap->cap_days,
                'used' => $used,
                'room' => Dec::max(Dec::sub((string) $cap->cap_days, $used), '0'),
            ];
        }

        return $out;
    }
}
