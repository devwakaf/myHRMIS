<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Enums\AssignableType;
use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Exceptions\HolidayListDoesNotCover;
use App\Domain\Core\Exceptions\NoHolidayListAssigned;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeWorkHistory;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Shared\Dating\DateRange;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which Senarai Cuti Umum governs an employee on a date (B6), and holiday
 * queries over ranges. Precedence: employee → branch (the employee's branch on
 * that date, from work history) → organisation; at each level the submitted
 * assignment with the latest effective_from ≤ date wins.
 *
 * Contract for M2–M4. Caching: per-instance memo only (bound as scoped).
 */
final class HolidayResolver
{
    /** @var array<int, HolidayList> */
    private array $lists = [];

    /** @var array<string, Collection<int, HolidayListAssignment>> */
    private array $assignments = [];

    public function listFor(Employee $employee, CarbonInterface|string $date, ?HolidayList $override = null): HolidayList
    {
        $day = DateRange::day($date);

        if ($override !== null) {
            $this->assertCovers($override, $day);

            return $override;
        }

        $levels = $this->levels($employee, $day);

        foreach ($levels as [$type, $id]) {
            $match = $this->assignmentsFor($type, $id)
                ->filter(fn (HolidayListAssignment $a) => $a->effective_from->lessThanOrEqualTo($day))
                ->sortByDesc(fn (HolidayListAssignment $a) => $a->effective_from->getTimestamp())
                ->first();

            if ($match !== null) {
                $list = $this->list($match->holiday_list_id);
                $this->assertCovers($list, $day);

                return $list;
            }
        }

        // Fallback: earliest assignment of the most specific level that has any.
        foreach ($levels as [$type, $id]) {
            $earliest = $this->assignmentsFor($type, $id)
                ->sortBy(fn (HolidayListAssignment $a) => $a->effective_from->getTimestamp())
                ->first();

            if ($earliest !== null) {
                $list = clone $this->list($earliest->holiday_list_id);
                $list->resolvedByFallback = true;
                $this->assertCovers($list, $day);

                return $list;
            }
        }

        throw new NoHolidayListAssigned('core.holiday_resolver.none_assigned', ['employee' => $employee->full_name]);
    }

    /**
     * Split a range wherever the governing list changes (assignment boundaries, branch changes).
     *
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, list: HolidayList}>
     */
    public function segments(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to, ?HolidayList $override = null): array
    {
        $range = new DateRange($from, $to);

        if ($override !== null) {
            return [['from' => $range->from, 'to' => $range->to ?? $range->from, 'list' => $this->listFor($employee, $range->from, $override)]];
        }

        $boundaries = [];
        $branchIds = EmployeeWorkHistory::query()->where('employee_id', $employee->id)->pluck('branch_id')
            ->push($employee->branch_id)->filter()->unique();

        $candidates = [[AssignableType::Employee, $employee->id], [AssignableType::Organisation, $employee->organisation_id]];
        foreach ($branchIds as $branchId) {
            $candidates[] = [AssignableType::Branch, (int) $branchId];
        }
        foreach ($candidates as [$type, $id]) {
            foreach ($this->assignmentsFor($type, $id) as $assignment) {
                $boundaries[] = $assignment->effective_from;
            }
        }

        $history = EmployeeWorkHistory::query()->where('employee_id', $employee->id)->orderBy('from_date')->get();
        $previousBranch = null;
        foreach ($history as $row) {
            if ($previousBranch !== null && $row->branch_id !== $previousBranch) {
                $boundaries[] = $row->from_date;
            }
            $previousBranch = $row->branch_id;
        }

        $segments = [];
        foreach ($range->splitAt($boundaries) as $piece) {
            $list = $this->listFor($employee, $piece->from);
            $end = $piece->to ?? $piece->from;
            $last = $segments === [] ? null : $segments[array_key_last($segments)];

            if ($last !== null && $last['list']->id === $list->id && $last['to']->addDay()->equalTo($piece->from)) {
                $segments[array_key_last($segments)]['to'] = $end;

                continue;
            }

            $segments[] = ['from' => $piece->from, 'to' => $end, 'list' => $list];
        }

        return $segments;
    }

    /**
     * @return Collection<string, array{kind: string, isHalfDay: bool, name: string, listId: int}>
     */
    public function holidaysBetween(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to, ?HolidayList $override = null): Collection
    {
        $out = collect();

        foreach ($this->segments($employee, $from, $to, $override) as $segment) {
            $rows = $segment['list']->holidays()
                ->whereDate('date', '>=', $segment['from']->toDateString())
                ->whereDate('date', '<=', $segment['to']->toDateString())
                ->orderBy('date')
                ->get();

            foreach ($rows as $holiday) {
                $out->put($holiday->date->toDateString(), [
                    'kind' => $holiday->kind->value,
                    'isHalfDay' => $holiday->is_half_day,
                    'name' => $holiday->displayName(),
                    'listId' => $segment['list']->id,
                ]);
            }
        }

        return $out->sortKeys();
    }

    public function isHoliday(Employee $employee, CarbonInterface|string $date, ?HolidayList $override = null): bool
    {
        return $this->holidaysBetween($employee, $date, $date, $override)->isNotEmpty();
    }

    public function isWeeklyOff(Employee $employee, CarbonInterface|string $date, ?HolidayList $override = null): bool
    {
        $row = $this->holidaysBetween($employee, $date, $date, $override)->first();

        return $row !== null && $row['kind'] === HolidayKind::WeeklyOff->value;
    }

    /**
     * Working days in the range as a decimal string (scale 3). Half-day holidays
     * count 0.5 non-working when $countHalfDays is true, else they count as working.
     */
    public function workingDaysBetween(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to, bool $countHalfDays = true, ?HolidayList $override = null): string
    {
        $range = new DateRange($from, $to);
        $days = (string) $range->days();
        $off = '0';

        foreach ($this->holidaysBetween($employee, $range->from, $range->to ?? $range->from, $override) as $holiday) {
            if ($holiday['isHalfDay']) {
                if ($countHalfDays) {
                    $off = bcadd($off, '0.5', 3);
                }
            } else {
                $off = bcadd($off, '1', 3);
            }
        }

        return bcsub($days, $off, 3);
    }

    /**
     * @return list<array{0: AssignableType, 1: int}>
     */
    private function levels(Employee $employee, CarbonImmutable $day): array
    {
        $levels = [[AssignableType::Employee, $employee->id]];

        $branchId = EmployeeSnapshot::asAt($employee, $day)->branchId;
        if ($branchId !== null) {
            $levels[] = [AssignableType::Branch, $branchId];
        }

        $levels[] = [AssignableType::Organisation, $employee->organisation_id];

        return $levels;
    }

    /**
     * @return Collection<int, HolidayListAssignment>
     */
    private function assignmentsFor(AssignableType $type, int $id): Collection
    {
        $key = $type->value.':'.$id;

        return $this->assignments[$key] ??= HolidayListAssignment::query()
            ->where('assignable_type', $type->value)
            ->where('assignable_id', $id)
            ->where('status', DocumentStatus::Submitted->value)
            ->get()
            ->toBase();
    }

    private function list(int $id): HolidayList
    {
        return $this->lists[$id] ??= HolidayList::query()->findOrFail($id);
    }

    private function assertCovers(HolidayList $list, CarbonImmutable $day): void
    {
        if (! $list->covers($day)) {
            throw new HolidayListDoesNotCover('core.holiday_resolver.not_covered', [
                'list' => $list->displayName(),
                'date' => $day->format('d/m/Y'),
            ]);
        }
    }

    /** Clear the memo (e.g. long-running jobs after assignments change). */
    public function flush(): void
    {
        $this->lists = [];
        $this->assignments = [];
    }
}
