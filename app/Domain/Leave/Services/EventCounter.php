<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-event types (spec-02 §3.10): each approved or pending application is one
 * event, counted across the employee chain (this record + previous_employee_id
 * ancestors). Within a period for max_events_per_period.
 */
final class EventCounter
{
    public function count(Employee $employee, int $leaveTypeId, ?LeavePeriod $period = null, ?int $excludeApplicationId = null): int
    {
        return $this->query($employee, $leaveTypeId, $period, $excludeApplicationId)->count();
    }

    /** HR on-behalf may split one event (same event_date) into several applications. */
    public function sameEventExists(Employee $employee, int $leaveTypeId, string $eventDate, ?int $excludeApplicationId = null): bool
    {
        return $this->query($employee, $leaveTypeId, null, $excludeApplicationId)->whereDate('event_date', $eventDate)->exists();
    }

    /**
     * @return list<int>
     */
    public static function chain(Employee $employee): array
    {
        $ids = [];
        $current = $employee;
        while ($current !== null && ! in_array($current->id, $ids, true)) {
            $ids[] = $current->id;
            $current = $current->previous_employee_id === null ? null
                : Employee::acrossOrganisations()->find($current->previous_employee_id);
        }

        return $ids;
    }

    /**
     * @return Builder<LeaveApplication>
     */
    private function query(Employee $employee, int $leaveTypeId, ?LeavePeriod $period, ?int $excludeApplicationId): Builder
    {
        return LeaveApplication::query()
            ->whereIn('employee_id', self::chain($employee))
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
            ->when($excludeApplicationId !== null, fn ($q) => $q->whereKeyNot($excludeApplicationId))
            ->when($period !== null, fn ($q) => $q->whereBetween('from_date', [$period->from_date->toDateString(), $period->to_date->toDateString()]));
    }
}
