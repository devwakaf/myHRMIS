<?php

declare(strict_types=1);

namespace App\Domain\Leave\Services;

use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeSnapshot;
use App\Domain\Leave\Models\LeaveBlockList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Senarai Sekatan Cuti (spec-02 §2.13, §3.9 step 10): dates on which leave of a
 * type is discouraged for a department (descendants included). Applicants must
 * acknowledge; the final approval needs an exempt approver or an HR override.
 */
final class BlockListChecker
{
    /**
     * @param  list<CarbonImmutable>  $dates  dates that deduct leave
     * @return list<array{list_id: int, list: string, date: string, reason: string|null}>
     */
    public function hits(Employee $employee, int $leaveTypeId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $lists = LeaveBlockList::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('leave_type_id')->orWhere('leave_type_id', $leaveTypeId))
            ->with(['dates', 'departments:id'])
            ->get();
        if ($lists->isEmpty()) {
            return [];
        }

        $wanted = array_map(fn (CarbonImmutable $d) => $d->toDateString(), $dates);
        $hits = [];

        foreach ($lists as $list) {
            foreach ($list->dates as $blocked) {
                $date = $blocked->date->toDateString();
                if (! in_array($date, $wanted, true)) {
                    continue;
                }
                if (! $list->applies_to_all_departments && ! $this->inDepartments($employee, $blocked->date, $list->departments->pluck('id')->all())) {
                    continue;
                }
                $hits[] = ['list_id' => $list->id, 'list' => $list->name, 'date' => $date, 'reason' => $blocked->reason];
            }
        }

        return $hits;
    }

    /**
     * Whether, for every list hit, at least one of the users is exempt.
     *
     * @param  list<array{list_id: int, list: string, date: string, reason: string|null}>  $hits
     * @param  list<int>  $userIds
     */
    public function exempted(array $hits, array $userIds): bool
    {
        if ($hits === []) {
            return true;
        }
        if ($userIds === []) {
            return false;
        }

        $listIds = array_values(array_unique(array_column($hits, 'list_id')));
        $exemptLists = DB::table('leave_block_list_exempt_users')
            ->whereIn('leave_block_list_id', $listIds)->whereIn('user_id', $userIds)
            ->distinct()->pluck('leave_block_list_id')->map(fn ($id) => (int) $id)->all();

        return array_diff($listIds, $exemptLists) === [];
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private function inDepartments(Employee $employee, CarbonImmutable $date, array $departmentIds): bool
    {
        $departmentId = EmployeeSnapshot::asAt($employee, $date)->departmentId;
        if ($departmentId === null || $departmentIds === []) {
            return false;
        }

        $path = (string) Department::query()->whereKey($departmentId)->value('path');
        foreach ($departmentIds as $id) {
            if (str_contains($path, '/'.$id.'/')) {
                return true;
            }
        }

        return false;
    }
}
