<?php

declare(strict_types=1);

namespace App\Domain\Core\Reports;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * B5.3 Hari Lahir and B5.4 Kakitangan Berhenti.
 */
final class PeopleReports
{
    /**
     * Birthdays in a month: day and month only; year/age only with view-sensitive.
     *
     * @return list<array<string, mixed>>
     */
    public function birthdays(int $month, bool $sensitive): array
    {
        return Employee::query()
            ->where('status', '!=', EmployeeStatus::Left->value)
            ->whereMonth('date_of_birth', $month)
            ->with(['department'])
            ->get()
            ->sortBy(fn (Employee $e) => $e->date_of_birth->day)
            ->map(function (Employee $e) use ($sensitive): array {
                $row = [
                    'id' => $e->id,
                    'full_name' => $e->displayName(),
                    'department' => $e->department?->displayName(),
                    'birthday' => $e->date_of_birth->format('d/m'),
                ];
                if ($sensitive) {
                    $row['date_of_birth'] = $e->date_of_birth->toDateString();
                    $row['age'] = today()->year - $e->date_of_birth->year; // age reached this year
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Leavers whose relieving date falls in the range.
     *
     * @return list<array<string, mixed>>
     */
    public function leavers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Employee::query()
            ->whereNotNull('relieving_date')
            ->whereDate('relieving_date', '>=', $from->toDateString())
            ->whereDate('relieving_date', '<=', $to->toDateString())
            ->with('department')
            ->orderBy('relieving_date')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'employee_no' => $e->employee_no,
                'full_name' => $e->full_name,
                'department' => $e->department?->displayName(),
                'relieving_date' => $e->relieving_date?->toDateString(),
                'reason' => $e->separation_reason?->label(),
                'tenure_years' => number_format($e->serviceStart()->diffInDays($e->relieving_date) / 365.25, 1, '.', ''),
                'eligible_for_rehire' => $e->getAttribute('eligible_for_rehire'),
            ])
            ->values()
            ->all();
    }
}
