<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Attendance\Contracts\AttendanceForPayroll;
use App\Domain\Attendance\Support\AttendancePeriodSummary;
use App\Domain\Attendance\Support\OvertimePayLine;
use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Test double for Attendance → Payroll (spec-03 §5.2): configurable absences,
 * unmarked days and OT lines; records markOvertimePosted / releaseOvertime.
 */
final class FakeAttendanceForPayroll implements AttendanceForPayroll
{
    /** @var array<int, list<array{date: string, fraction: string}>> employee id => absent dates */
    public array $absences = [];

    /** @var array<int, list<string>> employee id => unmarked dates */
    public array $unmarked = [];

    /** @var array<int, list<OvertimePayLine>> employee id => OT lines */
    public array $overtime = [];

    /** @var list<array{claims: list<int>, run: int}> */
    public array $posted = [];

    /** @var list<int> */
    public array $released = [];

    public function periodSummary(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): AttendancePeriodSummary
    {
        $absent = $this->absences[$employee->id] ?? [];
        $total = '0';
        foreach ($absent as $row) {
            $total = bcadd($total, $row['fraction'], 3);
        }
        $unmarked = $this->unmarked[$employee->id] ?? [];

        return new AttendancePeriodSummary($total, $absent, count($unmarked), $unmarked, [], '0', '0', '0', '0', [], [], 0, 0, 0, 0);
    }

    public function completeness(int $organisationId, CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds = null): array
    {
        return [];
    }

    public function overtimePayLines(Employee $employee, CarbonImmutable $periodMonth): array
    {
        $postedClaims = array_merge([], ...array_map(fn (array $p) => $p['claims'], $this->posted));

        return array_values(array_filter(
            $this->overtime[$employee->id] ?? [],
            fn (OvertimePayLine $line) => ! in_array($line->claimId, $postedClaims, true) && $line->claimMonth->lessThanOrEqualTo($periodMonth->startOfMonth()),
        ));
    }

    public function markOvertimePosted(array $claimIds, int $payrollRunId): void
    {
        $this->posted[] = ['claims' => $claimIds, 'run' => $payrollRunId];
    }

    public function releaseOvertime(int $payrollRunId): void
    {
        $this->released[] = $payrollRunId;
        $this->posted = array_values(array_filter($this->posted, fn (array $p) => $p['run'] !== $payrollRunId));
    }
}
