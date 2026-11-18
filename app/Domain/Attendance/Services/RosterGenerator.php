<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\AssignmentChangeCause;
use App\Domain\Attendance\Enums\RestDaySource;
use App\Domain\Attendance\Enums\ScheduleDayKind;
use App\Domain\Attendance\Enums\ShiftAssignmentSource;
use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeRestDay;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftScheduleAssignment;
use App\Domain\Attendance\Models\ShiftScheduleDay;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Roster generation (spec-03 §4.9 ExtendRosters): walks each enabled Penugasan
 * Roster up to the horizon, collapsing consecutive same-shift slots into one
 * assignment (source roster) and writing rest slots as employee_rest_days.
 * Dates already covered by a manual / request assignment are skipped.
 */
final class RosterGenerator
{
    public function __construct(private readonly ShiftAssignmentWriter $writer) {}

    /**
     * @return array{assignments: int, rest_days: int}
     */
    public function extend(ShiftScheduleAssignment $roster, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $stats = ['assignments' => 0, 'rest_days' => 0];
        if (! $roster->is_enabled) {
            return $stats;
        }

        $settings = AttendanceSettings::current($roster->organisation_id);
        $start = $roster->generated_through === null ? $roster->anchor_date : $roster->generated_through->addDay()->max($roster->anchor_date);
        $end = $today->addDays($settings->roster_horizon_days);
        if ($roster->end_date !== null) {
            $end = $end->min($roster->end_date);
        }
        if ($end->lessThan($start)) {
            return $stats;
        }

        $roster->loadMissing('schedule.days');
        $cycle = max(1, $roster->schedule->cycle_length_days);
        /** @var array<int, ShiftScheduleDay> $slots */
        $slots = $roster->schedule->days->keyBy('day_index')->all();
        $employee = Employee::query()->findOrFail($roster->employee_id);

        DB::transaction(function () use ($roster, $start, $end, $cycle, $slots, $employee, &$stats): void {
            /** @var list<array{shift: int, from: CarbonImmutable, to: CarbonImmutable}> $runs */
            $runs = [];
            $previous = null; // date of the last shift slot added to the current run

            for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
                $index = ((int) $roster->anchor_date->diffInDays($d)) % $cycle;
                $slot = $slots[$index] ?? null;

                if ($slot !== null && $slot->kind === ScheduleDayKind::Rest) {
                    $rest = EmployeeRestDay::query()->firstOrCreate(
                        ['employee_id' => $employee->id, 'rest_date' => $d->toDateString()],
                        ['organisation_id' => $employee->organisation_id, 'source' => RestDaySource::Roster, 'shift_schedule_assignment_id' => $roster->id],
                    );
                    if ($rest->wasRecentlyCreated) {
                        $stats['rest_days']++;
                    }

                    continue;
                }

                if ($slot === null || $slot->kind === ScheduleDayKind::Off || $slot->shift_type_id === null
                    || ShiftAssignment::query()->where('employee_id', $employee->id)->overlapping($d, $d)->exists()) {
                    continue; // nothing to generate, or a manual / request assignment wins
                }

                $last = array_key_last($runs);
                if ($last !== null && $previous !== null && $runs[$last]['shift'] === $slot->shift_type_id && $previous->addDay()->equalTo($d)) {
                    $runs[$last]['to'] = $d;
                } else {
                    $runs[] = ['shift' => $slot->shift_type_id, 'from' => $d, 'to' => $d];
                }
                $previous = $d;
            }

            foreach ($runs as $run) {
                $this->writer->add($employee, ShiftType::query()->findOrFail($run['shift']), $run['from'], $run['to'], ShiftAssignmentSource::Roster, [
                    'shift_schedule_assignment_id' => $roster->id,
                    'overtime_type_id' => $roster->overtime_type_id,
                ]);
                $stats['assignments']++;
            }

            $roster->forceFill(['generated_through' => $end->toDateString()])->save();
        });

        return $stats;
    }

    /**
     * Create a Penugasan Roster and generate it immediately.
     *
     * @param  array<string, mixed>  $data  employee_id, shift_schedule_id, anchor_date, end_date?, overtime_type_id?, notes?
     */
    public function assign(array $data): ShiftScheduleAssignment
    {
        $anchor = CarbonImmutable::parse($data['anchor_date']);
        $endDate = isset($data['end_date']) ? CarbonImmutable::parse($data['end_date']) : null;
        if ($endDate !== null && $endDate->lessThan($anchor)) {
            throw new AttendanceRuleViolation('attendance.errors.range_invalid');
        }

        $overlap = ShiftScheduleAssignment::query()->where('employee_id', $data['employee_id'])->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $anchor->toDateString()))
            ->when($endDate !== null, fn ($q) => $q->whereDate('anchor_date', '<=', $endDate->toDateString()))
            ->exists();
        if ($overlap) {
            throw new AttendanceRuleViolation('attendance.errors.roster_overlap');
        }

        $roster = ShiftScheduleAssignment::query()->create([
            'employee_id' => $data['employee_id'],
            'shift_schedule_id' => $data['shift_schedule_id'],
            'anchor_date' => $anchor->toDateString(),
            'end_date' => $endDate?->toDateString(),
            'overtime_type_id' => $data['overtime_type_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_enabled' => true,
        ]);
        $this->extend($roster);

        return $roster;
    }

    /** Stop a roster from $stopDate: end = stop − 1, later generated rows removed. */
    public function stop(ShiftScheduleAssignment $roster, CarbonImmutable $stopDate): void
    {
        DB::transaction(function () use ($roster, $stopDate): void {
            $lastDay = $stopDate->subDay();
            $roster->forceFill([
                'end_date' => $lastDay->toDateString(),
                'is_enabled' => $lastDay->greaterThanOrEqualTo($roster->anchor_date),
            ])->save();
            $employee = Employee::query()->findOrFail($roster->employee_id);
            $this->writer->truncateAfter($employee, $lastDay, AssignmentChangeCause::RosterEdit, $roster->id, $roster->id);
        });
    }
}
