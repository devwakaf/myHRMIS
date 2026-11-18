<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeValidator;
use App\Domain\Core\Services\WorkHistoryRecorder;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Pindahkan laporan": move every non-left direct report to another manager in bulk.
 */
final class ReassignDirectReports
{
    public function __construct(
        private readonly EmployeeValidator $validator,
        private readonly WorkHistoryRecorder $history,
    ) {}

    public function handle(Employee $from, ?int $toEmployeeId, User $actor): int
    {
        $reports = Employee::query()->where('reports_to_id', $from->id)
            ->where('status', '!=', EmployeeStatus::Left->value)->get();

        if ($toEmployeeId !== null) {
            foreach ($reports as $report) {
                $error = $this->validator->reportsToError($toEmployeeId, $report);
                if ($error !== null) {
                    throw DomainValidation::withMessages(['reports_to_id' => $error]);
                }
            }
        }

        DB::transaction(function () use ($reports, $toEmployeeId, $from, $actor): void {
            foreach ($reports as $report) {
                $report->update(['reports_to_id' => $toEmployeeId]);
                $this->history->correctCurrent($report);
                activity('core')->performedOn($report)->causedBy($actor)->event('correction')
                    ->withProperties(['reason' => 'reassign_reports', 'from_manager' => $from->id, 'to_manager' => $toEmployeeId])
                    ->log('correction');
            }
        });

        return $reports->count();
    }
}
