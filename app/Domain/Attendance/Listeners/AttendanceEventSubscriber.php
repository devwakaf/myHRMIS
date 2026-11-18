<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Listeners;

use App\Domain\Attendance\Actions\SeedAttendanceDefaults;
use App\Domain\Attendance\Enums\AssignmentChangeCause;
use App\Domain\Attendance\Enums\AttendanceSource;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\Attendance;
use App\Domain\Attendance\Models\AttendanceRequest;
use App\Domain\Attendance\Models\AttendanceSettings;
use App\Domain\Attendance\Models\ShiftRequest;
use App\Domain\Attendance\Models\ShiftScheduleAssignment;
use App\Domain\Attendance\Services\AttendanceWriter;
use App\Domain\Attendance\Services\DayTypeResolver;
use App\Domain\Attendance\Services\ShiftAssignmentWriter;
use App\Domain\Core\Events\EmployeeSeparated;
use App\Domain\Core\Events\HolidayAssignmentChanged;
use App\Domain\Core\Events\HolidayListChanged;
use App\Domain\Core\Events\OrganisationCreated;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Services\HolidayResolver;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Kehadiran's reactions to Core events (spec-03 §4.9, §5.4): defaults for new
 * organisations, separation (assignments / rosters trimmed, pending requests
 * cancelled by the system), and holiday changes (auto absents on dates that
 * became holidays are cancelled; removed holidays are picked up by MarkAbsences).
 * Approval effects are NOT here — they run in the Approvable hooks.
 */
final class AttendanceEventSubscriber
{
    public function onOrganisationCreated(OrganisationCreated $event): void
    {
        app(SeedAttendanceDefaults::class)->handle($event->organisation->id);
    }

    public function onEmployeeSeparated(EmployeeSeparated $event): void
    {
        $employee = $event->employee;
        OrganisationContext::run((int) $employee->organisation_id, function () use ($employee): void {
            $fresh = Employee::query()->findOrFail($employee->id);
            if ($fresh->relieving_date === null) {
                return;
            }
            $relieving = $fresh->relieving_date;

            DB::transaction(function () use ($fresh, $relieving): void {
                app(ShiftAssignmentWriter::class)->truncateAfter($fresh, $relieving, AssignmentChangeCause::Separation, $fresh->id);
                foreach (ShiftScheduleAssignment::query()->where('employee_id', $fresh->id)->where('is_enabled', true)->get() as $roster) {
                    if ($roster->end_date === null || $roster->end_date->greaterThan($relieving)) {
                        $roster->forceFill(['end_date' => $relieving->toDateString(), 'is_enabled' => ! $relieving->lessThan($roster->anchor_date)])->save();
                    }
                }
            });

            $approvals = app(ApprovalService::class);
            $reason = (string) __('attendance.messages.cancelled_on_separation');
            foreach ([AttendanceRequest::class, ShiftRequest::class] as $class) {
                $pending = $class::query()->where('employee_id', $fresh->id)->where('status', ApprovalStatus::Pending->value)
                    ->whereDate('to_date', '>', $relieving->toDateString())->get();
                foreach ($pending as $doc) {
                    $request = $doc->approvalRequest()->first();
                    if ($request !== null) {
                        $approvals->cancel($request, null, $reason);
                    }
                }
            }
        });
    }

    public function onHolidayListChanged(HolidayListChanged $event): void
    {
        if ($event->addedDates === []) {
            return;
        }
        $orgId = HolidayList::acrossOrganisations()->whereKey($event->holidayListId)->value('organisation_id');
        if ($orgId === null) {
            return;
        }

        OrganisationContext::run((int) $orgId, function () use ($event): void {
            $rows = Attendance::query()->live()->where('source', AttendanceSource::Auto->value)
                ->where('attendance_status', AttendanceStatus::Absent->value)
                ->whereIn('attendance_date', $event->addedDates)->get();
            $this->cancelAbsentsNoLongerExpected($rows->all(), 'holiday_list:'.$event->holidayListId);
        });
    }

    public function onHolidayAssignmentChanged(HolidayAssignmentChanged $event): void
    {
        $assignment = $event->assignment;
        OrganisationContext::run((int) $assignment->organisation_id, function () use ($assignment): void {
            $lookback = CarbonImmutable::today()->subDays(AttendanceSettings::current()->absent_lookback_days);
            $from = $assignment->effective_from->max($lookback);
            $rows = Attendance::query()->live()->where('source', AttendanceSource::Auto->value)
                ->where('attendance_status', AttendanceStatus::Absent->value)
                ->whereDate('attendance_date', '>=', $from->toDateString())->get();
            $this->cancelAbsentsNoLongerExpected($rows->all(), 'holiday_assignment:'.$assignment->id);
        });
    }

    /**
     * @param  list<Attendance>  $rows
     */
    private function cancelAbsentsNoLongerExpected(array $rows, string $reason): void
    {
        app(HolidayResolver::class)->flush();
        $dayTypes = app(DayTypeResolver::class);
        $writer = app(AttendanceWriter::class);

        foreach ($rows as $row) {
            $employee = Employee::query()->find($row->employee_id);
            if ($employee === null) {
                continue;
            }
            if (! $dayTypes->for($employee, $row->attendance_date, $row->shiftType)->isNormal()) {
                $writer->cancel($row, null, $reason);
            }
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrganisationCreated::class => 'onOrganisationCreated',
            EmployeeSeparated::class => 'onEmployeeSeparated',
            HolidayListChanged::class => 'onHolidayListChanged',
            HolidayAssignmentChanged::class => 'onHolidayAssignmentChanged',
        ];
    }
}
