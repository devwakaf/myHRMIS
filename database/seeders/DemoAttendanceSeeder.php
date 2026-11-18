<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Attendance\Actions\AttendanceConfigActions;
use App\Domain\Attendance\Actions\AttendanceRequestActions;
use App\Domain\Attendance\Actions\OvertimeClaimActions;
use App\Domain\Attendance\Actions\ShiftAssignmentActions;
use App\Domain\Attendance\Actions\ShiftRequestActions;
use App\Domain\Attendance\Enums\CheckinSource;
use App\Domain\Attendance\Enums\GeofenceResult;
use App\Domain\Attendance\Enums\LogType;
use App\Domain\Attendance\Models\EmployeeCheckin;
use App\Domain\Attendance\Models\OvertimeClaim;
use App\Domain\Attendance\Models\ShiftLocation;
use App\Domain\Attendance\Models\ShiftSchedule;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\AbsenceMarker;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\RosterGenerator;
use App\Domain\Attendance\Services\ShiftCalendar;
use App\Domain\Attendance\Support\ShiftOccurrence;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Approval\Enums\AssigneeDecision;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Demo data for the Kehadiran module (M3 UI) in the Pahang demo Organisasi: two
 * Lokasi Shift, shift assignments (manual, bulk and roster patterns), about four weeks
 * of check-ins turned into attendance records (on time, late, early exit, overtime,
 * single punch, off-shift, absent), Permohonan Kehadiran / Shift in every state and
 * Tuntutan OT drafts plus one submitted claim. Idempotent; never runs in production.
 * Coordinates are synthetic points near the demo locations.
 *
 * `php artisan db:seed --class=DemoAttendanceSeeder`
 */
class DemoAttendanceSeeder extends Seeder
{
    private const MARKER = 'Pejabat Kuantan';

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $org = Organisation::query()->where('short_code', 'SDT')->first();
        $hr = User::query()->where('email', 'hr.manager@myhrmis.test')->first();
        if ($org === null || $hr === null) {
            return;
        }

        OrganisationContext::run($org, function () use ($hr): void {
            if (ShiftLocation::query()->where('name', self::MARKER)->exists()) {
                return;
            }
            $office = ShiftType::query()->where('code', 'PEJABAT')->first();
            $half = ShiftType::query()->where('code', 'SEPARUH')->first();
            $weekly = ShiftSchedule::query()->where('code', '5H-SS')->first();
            $cycle = ShiftSchedule::query()->where('code', '4K4R')->first();
            if ($office === null || $half === null || $weekly === null || $cycle === null) {
                $this->warn('Tiada lalai Kehadiran; jalankan AttendanceDefaultsSeeder dahulu.');

                return;
            }

            $today = CarbonImmutable::today();
            $start = $today->subWeeks(4)->startOfWeek();
            $locations = $this->locations();
            $this->prepareShiftTypes($start);
            $employees = Employee::query()->notLeft()->orderBy('id')->get();

            $this->assignments($employees, $office, $half, $weekly, $cycle, $start, $today);
            $this->punches($employees, $locations, $start, $today);
            $this->process();
            $this->requests($hr, $half, $today);
            $this->claims($hr, $today);
        });
    }

    /**
     * @return array<int, ShiftLocation> keyed by branch id
     */
    private function locations(): array
    {
        $actions = app(AttendanceConfigActions::class);
        $made = [];
        $rows = [
            ['KTN', self::MARKER, '3.8077000', '103.3260000'],
            ['TMR', 'Cawangan Temerloh', '3.4503000', '102.4175000'],
        ];
        foreach ($rows as [$code, $name, $lat, $lng]) {
            $branch = Branch::query()->where('code', $code)->first();
            $made[(int) $branch?->id] = $actions->saveLocation(null, [
                'name' => $name, 'branch_id' => $branch?->id, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => 150, 'is_active' => true,
                'address_note' => 'Lokasi contoh (data demo)',
            ], $branch === null ? [] : [['type' => 'branch', 'id' => $branch->id]]);
        }

        return $made;
    }

    /** Start processing from the demo window and let the office shift detect overtime. */
    private function prepareShiftTypes(CarbonImmutable $start): void
    {
        ShiftType::query()->update(['process_from' => $start->toDateString()]);
        ShiftType::query()->where('code', 'PEJABAT')->update(['overtime_enabled' => true]);
    }

    /**
     * @param  iterable<Employee>  $employees
     */
    private function assignments(iterable $employees, ShiftType $office, ShiftType $half, ShiftSchedule $weekly, ShiftSchedule $cycle, CarbonImmutable $start, CarbonImmutable $today): void
    {
        $manualOnes = [];
        $bulkOnes = [];
        foreach ($employees as $employee) {
            match ($employee->full_name) {
                'Budi Santoso' => $manualOnes[] = $employee,
                'Mohd Hafiz bin Yusof', 'Priya a/p Ramasamy' => $bulkOnes[] = $employee,
                default => null,
            };
        }

        try {
            $nextMonday = $today->next(CarbonImmutable::MONDAY);
            foreach ($manualOnes as $employee) {
                app(ShiftAssignmentActions::class)->create(['employee_id' => $employee->id, 'shift_type_id' => $half->id, 'start_date' => $nextMonday->toDateString(), 'end_date' => $nextMonday->addDays(4)->toDateString(), 'notes' => 'Contoh penugasan manual']);
            }
            if ($bulkOnes !== []) {
                app(ShiftAssignmentActions::class)->bulk(array_map(fn (Employee $e) => $e->id, $bulkOnes), [
                    'shift_type_id' => $half->id, 'start_date' => $nextMonday->addWeeks(2)->toDateString(), 'end_date' => $nextMonday->addWeeks(2)->addDays(4)->toDateString(), 'mode' => 'add',
                ], true);
            }
        } catch (Throwable $e) {
            $this->warn('Penugasan contoh gagal: '.$e->getMessage());
        }

        $hq = Branch::query()->where('code', 'KTN')->value('id');
        foreach ($employees as $employee) {
            try {
                app(RosterGenerator::class)->assign([
                    'employee_id' => $employee->id,
                    'shift_schedule_id' => ($employee->branch_id === $hq ? $weekly : $cycle)->id,
                    'anchor_date' => $start->max($employee->date_of_joining->startOfWeek())->toDateString(),
                ]);
            } catch (Throwable $e) {
                $this->warn('Roster contoh gagal untuk '.$employee->full_name.': '.$e->getMessage());
            }
        }
    }

    /**
     * Raw punches for every past shift day; the profile of a day is derived from the employee and date,
     * so the dataset is the same on every run.
     *
     * @param  iterable<Employee>  $employees
     * @param  array<int, ShiftLocation>  $locations
     */
    private function punches(iterable $employees, array $locations, CarbonImmutable $start, CarbonImmutable $today): void
    {
        $calendar = app(ShiftCalendar::class);
        $yesterday = $today->subDay();
        $absentFrom = $today->subDays(14);

        foreach ($employees as $employee) {
            $location = $locations[(int) $employee->branch_id] ?? reset($locations);
            for ($d = $start->max($employee->date_of_joining); $d->lessThanOrEqualTo($yesterday); $d = $d->addDay()) {
                $occurrence = $calendar->occurrenceOn($employee, $d);
                if ($occurrence === null) {
                    continue;
                }
                $roll = crc32($employee->id.'|'.$d->toDateString()) % 100;
                $startAt = $occurrence->start;
                $endAt = $occurrence->end;

                [$in, $out] = match (true) {
                    $roll < 3 && $d->greaterThanOrEqualTo($absentFrom) => [null, null],                  // absent: no punch
                    $roll < 7 => [$startAt->addMinutes(18 + $roll * 4), $endAt->addMinutes(3)],         // late
                    $roll < 10 => [$startAt->subMinutes(5), $endAt->subMinutes(45)],                    // early exit
                    $roll < 13 => [$startAt->subMinutes(3), $endAt->addMinutes(150)],                   // overtime
                    $roll < 15 => [$startAt->subMinutes(2), null],                                      // single punch
                    default => [$startAt->subMinutes($roll % 12), $endAt->addMinutes($roll % 9)],
                };

                foreach ([[$in, LogType::In], [$out, LogType::Out]] as [$at, $type]) {
                    if ($at !== null) {
                        $this->punch($employee, $occurrence, $location, $at, $type);
                    }
                }
            }
        }

        // One punch on a rest day (Ahad): lands in the review queue as "di luar shift".
        $sunday = $today->previous(CarbonImmutable::SUNDAY);
        $someone = Employee::query()->where('full_name', 'Nor Azman bin Abdullah')->first();
        if ($someone !== null && $sunday->greaterThanOrEqualTo($start)) {
            $this->punch($someone, null, reset($locations), $sunday->setTime(10, 30), LogType::In);
        }
    }

    private function punch(Employee $employee, ?ShiftOccurrence $occurrence, ShiftLocation $location, CarbonImmutable $at, LogType $type): void
    {
        $seed = crc32($employee->id.'|'.$at->toIso8601String());
        $punch = new EmployeeCheckin;
        $punch->forceFill(array_merge($occurrence?->snapshot() ?? ShiftOccurrence::emptySnapshot(), [
            'organisation_id' => $employee->organisation_id,
            'employee_id' => $employee->id,
            'punched_at' => $at,
            'received_at' => $at,
            'log_type' => $type,
            'source' => CheckinSource::Web,
            'latitude' => (float) $location->latitude + (($seed % 7) - 3) * 0.00008,
            'longitude' => (float) $location->longitude + (($seed % 5) - 2) * 0.00008,
            'accuracy_m' => 8 + $seed % 15,
            'shift_location_id' => $location->id,
            'distance_m' => 5 + $seed % 80,
            'geofence_result' => GeofenceResult::Inside,
        ]))->save();
    }

    private function process(): void
    {
        try {
            app(AttendanceProcessor::class)->run();
            app(AbsenceMarker::class)->run();
        } catch (Throwable $e) {
            $this->warn('Pemprosesan kehadiran contoh gagal: '.$e->getMessage());
        }
    }

    private function requests(User $hr, ShiftType $half, CarbonImmutable $today): void
    {
        $monday = $today->next(CarbonImmutable::MONDAY);
        $named = fn (string $name) => Employee::query()->where('full_name', $name)->first();
        $actorOf = fn (?Employee $e) => $e?->user_id !== null ? (User::query()->find($e->user_id) ?? $hr) : $hr;
        $attendance = app(AttendanceRequestActions::class);
        $shift = app(ShiftRequestActions::class);

        $file = function (callable $make) {
            try {
                return $make();
            } catch (Throwable $e) {
                $this->warn('Permohonan contoh gagal: '.$e->getMessage());

                return null;
            }
        };

        $irfan = $named('Muhammad Irfan bin Zulkifli');
        $kavitha = $named('Kavitha a/p Subramaniam');
        $haziq = $named('Haziq bin Rosli');

        if ($irfan !== null) {
            // Diluluskan: WFH dua hari
            $doc = $file(fn () => $attendance->submit($attendance->saveDraft(null, $irfan, [
                'kind' => 'wfh', 'from_date' => $monday->addDay()->toDateString(), 'to_date' => $monday->addDays(2)->toDateString(), 'explanation' => 'Kerja dari rumah — menunggu juruteknik pemasangan internet.',
            ]), $actorOf($irfan)));
            if ($doc !== null) {
                $this->decide($doc, true);
            }
            // Draf
            $file(fn () => $attendance->saveDraft(null, $irfan, [
                'kind' => 'wfh', 'from_date' => $monday->addWeeks(3)->toDateString(), 'to_date' => $monday->addWeeks(3)->toDateString(), 'explanation' => 'Draf contoh.',
            ]));
            // Permohonan shift menunggu
            $file(fn () => $shift->submit($shift->saveDraft(null, $irfan, [
                'shift_type_id' => $half->id, 'from_date' => $monday->addWeeks(4)->toDateString(), 'to_date' => $monday->addWeeks(4)->addDay()->toDateString(), 'reason' => 'Urusan keluarga pada waktu petang.',
            ]), $actorOf($irfan)));
        }
        if ($kavitha !== null) {
            // Menunggu: Tugas Luar
            $file(fn () => $attendance->submit($attendance->saveDraft(null, $kavitha, [
                'kind' => 'on_duty', 'from_date' => $monday->addWeeks(1)->toDateString(), 'to_date' => $monday->addWeeks(1)->toDateString(), 'duty_location' => 'Pejabat klien, Kuantan', 'explanation' => 'Mesyuarat bersama klien.',
            ]), $actorOf($kavitha)));
            // Diluluskan: tukar shift
            $doc = $file(fn () => $shift->submit($shift->saveDraft(null, $kavitha, [
                'shift_type_id' => $half->id, 'from_date' => $monday->addWeeks(5)->toDateString(), 'to_date' => $monday->addWeeks(5)->toDateString(), 'reason' => 'Temu janji doktor.',
            ]), $actorOf($kavitha)));
            if ($doc !== null) {
                $this->decide($doc, true);
            }
        }
        if ($haziq !== null) {
            // Ditolak
            $doc = $file(fn () => $attendance->submit($attendance->saveDraft(null, $haziq, [
                'kind' => 'wfh', 'from_date' => $monday->addWeeks(2)->toDateString(), 'to_date' => $monday->addWeeks(2)->toDateString(), 'explanation' => 'Contoh permohonan yang ditolak.',
            ]), $actorOf($haziq)));
            if ($doc !== null) {
                $this->decide($doc, false);
            }
        }
    }

    private function claims(User $hr, CarbonImmutable $today): void
    {
        $actions = app(OvertimeClaimActions::class);
        $claims = [];
        try {
            foreach ([$today->startOfMonth(), $today->subMonthNoOverflow()->startOfMonth()] as $month) {
                foreach ($actions->generate(CarbonImmutable::parse($month->toDateString())) as $claim) {
                    $claims[] = $claim;
                }
            }
        } catch (Throwable $e) {
            $this->warn('Draf OT contoh gagal: '.$e->getMessage());

            return;
        }

        $claims = array_values(array_filter($claims, fn (OvertimeClaim $c) => $c->lines()->exists()));
        $first = $claims[0] ?? null;
        if ($first !== null) {
            try {
                $actions->setManualWage($first, '3800.00');
                $first->refresh();
                $actions->submit($first->refresh(), $hr, 'Kerja lebih masa untuk projek sistem (data contoh).');
            } catch (Throwable $e) {
                $this->warn('Tuntutan OT contoh gagal dihantar: '.$e->getMessage());
            }
        }
    }

    /** Walk every approval step: approve (or reject at the first step) as its pending assignee. */
    private function decide(Model $document, bool $approve): void
    {
        $service = app(ApprovalService::class);
        $request = $document->approvalRequest()->first(); // @phpstan-ignore-line every demo document has HasApprovalRequest
        for ($i = 0; $request !== null && $i < 6 && $request->refresh()->isPending(); $i++) {
            $assignee = $request->activeStep()?->assignees()->where('decision', AssigneeDecision::Pending->value)->first();
            $user = $assignee === null ? null : User::query()->find($assignee->user_id);
            if ($user === null) {
                return;
            }
            try {
                $approve ? $service->approve($request, $user) : $service->reject($request, $user, 'Contoh: tidak dapat dilulus pada tarikh ini.');
            } catch (Throwable $e) {
                $this->warn('Keputusan contoh gagal: '.$e->getMessage());

                return;
            }
        }
    }

    private function warn(string $message): void
    {
        $this->command?->warn($message);
    }
}
