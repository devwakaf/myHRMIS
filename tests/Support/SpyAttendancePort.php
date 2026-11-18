<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Contracts\LeaveAttendancePort;
use App\Domain\Leave\Models\LeaveApplication;
use App\Domain\Leave\Support\WorkedDay;
use App\Domain\Shared\Dating\DateRange;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Test double for the Attendance port (spec-02 §5.1): records calls, returns
 * configured conflicts / worked days and can be told to fail.
 */
final class SpyAttendancePort implements LeaveAttendancePort
{
    /** @var list<int> */
    public array $marked = [];

    /** @var list<int> */
    public array $unmarked = [];

    /** @var list<array{date: string, session: string}> */
    public array $conflicts = [];

    /** @var array<string, string> date => status (Hadir etc.) */
    public array $worked = [];

    public bool $failOnMark = false;

    public static function install(): self
    {
        $spy = new self;
        app()->instance(LeaveAttendancePort::class, $spy);

        return $spy;
    }

    public function markLeave(LeaveApplication $application): void
    {
        if ($this->failOnMark) {
            throw new RuntimeException('attendance unavailable');
        }
        $this->marked[] = $application->id;
    }

    public function unmarkLeave(LeaveApplication $application): void
    {
        $this->unmarked[] = $application->id;
    }

    public function conflicts(Employee $employee, DateRange $range): array
    {
        return array_values(array_filter($this->conflicts, fn (array $c) => $range->contains($c['date'])));
    }

    public function workedOn(Employee $employee, CarbonImmutable $date): ?WorkedDay
    {
        $status = $this->worked[$date->toDateString()] ?? null;

        return $status === null ? null : new WorkedDay($date, $status);
    }
}
