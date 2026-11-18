<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\DayType;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\EmployeeRestDay;
use App\Domain\Attendance\Models\ShiftScheduleAssignment;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Support\DayInfo;
use App\Domain\Core\Enums\HolidayKind;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Shared\Support\DomainError;
use Carbon\CarbonInterface;

/**
 * Day type of a date for an employee (spec-03 §4.2): public holidays win; a
 * roster governing the date decides rest days (over the list's weekly-off
 * pattern); half-day holiday / weekly off → normal with is_half_day_off.
 */
final class DayTypeResolver
{
    public function __construct(private readonly HolidayResolver $holidays) {}

    public function for(Employee $employee, CarbonInterface $date, ?ShiftType $shift = null): DayInfo
    {
        $day = $date->format('Y-m-d');

        try {
            $holiday = $this->holidays->holidaysBetween($employee, $day, $day, $shift?->holidayList)->get($day);
        } catch (DomainError) {
            $holiday = null; // no list assigned / not covered: treat as an ordinary day
        }

        $kind = $holiday === null ? null : HolidayKind::tryFrom($holiday['kind']);
        $label = $holiday['name'] ?? null;

        if (in_array($kind, [HolidayKind::PublicHoliday, HolidayKind::State, HolidayKind::Replacement, HolidayKind::AdHoc], true)) {
            return $this->holidayInfo(DayType::PublicHoliday, (bool) $holiday['isHalfDay'], $label);
        }

        if ($kind === HolidayKind::Organisation) {
            $type = AttendanceSettings::current($employee->organisation_id)->organisation_holiday_day_type;

            return $this->holidayInfo($type, (bool) $holiday['isHalfDay'], $label);
        }

        $restDay = EmployeeRestDay::query()->where('employee_id', $employee->id)->whereDate('rest_date', $day)->exists();
        if ($restDay) {
            return new DayInfo(DayType::RestDay, false, null, 'roster');
        }

        if ($this->rosterGoverns($employee, $day)) {
            return new DayInfo(DayType::Normal, false, null, 'roster');
        }

        if ($kind === HolidayKind::WeeklyOff) {
            return $this->holidayInfo(DayType::RestDay, (bool) $holiday['isHalfDay'], $label);
        }

        return new DayInfo(DayType::Normal);
    }

    public function rosterGoverns(Employee $employee, string $date): bool
    {
        return ShiftScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('is_enabled', true)
            ->whereDate('anchor_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->exists();
    }

    private function holidayInfo(DayType $type, bool $isHalfDay, ?string $label): DayInfo
    {
        if ($isHalfDay) {
            return new DayInfo(DayType::Normal, true, $label, 'holiday_list');
        }

        return new DayInfo($type, false, $label, 'holiday_list');
    }
}
