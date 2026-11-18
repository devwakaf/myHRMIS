<?php

declare(strict_types=1);

namespace App\Domain\Core\Reports;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeDocument;

/**
 * B5.5 Tarikh Penting Akan Datang: probation ends, contract ends, work pass /
 * passport / document expiries and retirements within the next N days.
 */
final class UpcomingDatesReport
{
    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(array $filters): array
    {
        $days = max(1, min(730, (int) ($filters['days'] ?? 60)));
        $today = today()->toImmutable();
        $until = $today->addDays($days);
        $rows = [];

        $fields = [
            'probation' => 'probation_end_date',
            'contract' => 'contract_end_date',
            'work_pass' => 'work_pass_expiry',
            'passport' => 'passport_expiry',
            'retirement' => 'retirement_date',
        ];

        $employees = Employee::query()->where('status', '!=', EmployeeStatus::Left->value)->get();
        foreach ($employees as $employee) {
            foreach ($fields as $kind => $field) {
                $date = $employee->getAttribute($field);
                if ($date === null || $date->lessThan($today) || $date->greaterThan($until)) {
                    continue;
                }
                if ($kind === 'probation' && $employee->confirmed_on !== null) {
                    continue;
                }
                $rows[] = [
                    'employee_id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'employee_no' => $employee->employee_no,
                    'kind' => $kind,
                    'kind_label' => (string) __('core.reports.upcoming_kinds.'.$kind),
                    'date' => $date->toDateString(),
                    'days_left' => (int) $today->diffInDays($date),
                    'detail' => null,
                ];
            }
        }

        $documents = EmployeeDocument::query()->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', $today->toDateString())
            ->whereDate('expires_on', '<=', $until->toDateString())
            ->with('employee')->get();
        foreach ($documents as $document) {
            if ($document->employee === null || $document->employee->isLeft()) {
                continue;
            }
            $rows[] = [
                'employee_id' => $document->employee_id,
                'full_name' => $document->employee->full_name,
                'employee_no' => $document->employee->employee_no,
                'kind' => 'document',
                'kind_label' => (string) __('core.reports.upcoming_kinds.document'),
                'date' => $document->expires_on?->toDateString(),
                'days_left' => (int) $today->diffInDays($document->expires_on),
                'detail' => $document->title,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $rows;
    }
}
