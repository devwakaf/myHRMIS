<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\AssignmentChangeCause;
use App\Domain\Attendance\Enums\LocationLinkType;
use App\Domain\Attendance\Enums\ShiftAssignmentSource;
use App\Domain\Attendance\Models\ShiftAssignment;
use App\Domain\Attendance\Models\ShiftLocationLink;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\ShiftAssignmentWriter;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Support\DomainError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan Shift — single and bulk wizard (spec-03 §2.6, §4.9). Mode `add`
 * fails on conflict; `replace` splits existing assignments through replaceRange.
 * The preview runs the same code inside a rolled-back transaction.
 */
final class ShiftAssignmentActions
{
    public function __construct(private readonly ShiftAssignmentWriter $writer) {}

    /**
     * @param  array{employee_id: int, shift_type_id: int, start_date: string, end_date?: string|null, overtime_type_id?: int|null, notes?: string|null, location_ids?: list<int>}  $data
     */
    public function create(array $data): ShiftAssignment
    {
        return DB::transaction(function () use ($data): ShiftAssignment {
            $employee = Employee::query()->findOrFail($data['employee_id']);
            $assignment = $this->writer->add(
                $employee,
                ShiftType::query()->findOrFail($data['shift_type_id']),
                CarbonImmutable::parse($data['start_date']),
                empty($data['end_date']) ? null : CarbonImmutable::parse($data['end_date']),
                ShiftAssignmentSource::Manual,
                ['overtime_type_id' => $data['overtime_type_id'] ?? null, 'notes' => $data['notes'] ?? null],
            );
            $this->link($assignment, $data['location_ids'] ?? []);

            return $assignment;
        });
    }

    /**
     * Bulk wizard step 3 (preview, nothing saved) or step 4 (commit).
     *
     * @param  list<int>  $employeeIds
     * @param  array{shift_type_id: int, start_date: string, end_date?: string|null, mode: string, overtime_type_id?: int|null, location_ids?: list<int>}  $data
     * @return list<array{employee_id: int, employee: string, result: string, reason: string|null}>
     */
    public function bulk(array $employeeIds, array $data, bool $commit): array
    {
        $shift = ShiftType::query()->findOrFail($data['shift_type_id']);
        $from = CarbonImmutable::parse($data['start_date']);
        $to = empty($data['end_date']) ? null : CarbonImmutable::parse($data['end_date']);
        $replace = $data['mode'] === 'replace';
        $causeId = (int) (microtime(true) * 1000) % 2147483647;
        $results = [];

        $run = function () use ($employeeIds, $shift, $from, $to, $replace, $causeId, $data, &$results): void {
            foreach (Employee::query()->whereIn('id', $employeeIds)->orderBy('full_name')->get() as $employee) {
                $hadOverlap = ShiftAssignment::query()->where('employee_id', $employee->id)->overlapping($from, $to)->exists();
                try {
                    DB::transaction(function () use ($employee, $shift, $from, $to, $replace, $causeId, $data): void {
                        $extra = ['overtime_type_id' => $data['overtime_type_id'] ?? null];
                        $assignment = $replace
                            ? $this->writer->replaceRange($employee, $from, $to ?? $from->addYears(50), $shift, AssignmentChangeCause::BulkReplace, $causeId, ShiftAssignmentSource::Bulk, $extra)
                            : $this->writer->add($employee, $shift, $from, $to, ShiftAssignmentSource::Bulk, $extra);
                        if ($assignment !== null && $to === null && $replace) {
                            $assignment->forceFill(['end_date' => null])->save();
                        }
                        if ($assignment !== null) {
                            $this->link($assignment, $data['location_ids'] ?? []);
                        }
                    });
                    $results[] = ['employee_id' => $employee->id, 'employee' => $employee->full_name, 'result' => $replace && $hadOverlap ? 'replaced' : 'created', 'reason' => null];
                } catch (DomainError $e) {
                    $results[] = ['employee_id' => $employee->id, 'employee' => $employee->full_name, 'result' => 'conflict', 'reason' => $e->userMessage()];
                }
            }
        };

        if ($commit) {
            $run();
        } else {
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        }

        return $results;
    }

    /**
     * @param  list<int>  $locationIds
     */
    private function link(ShiftAssignment $assignment, array $locationIds): void
    {
        foreach ($locationIds as $locationId) {
            ShiftLocationLink::query()->firstOrCreate([
                'shift_location_id' => (int) $locationId,
                'linkable_type' => LocationLinkType::ShiftAssignment->value,
                'linkable_id' => $assignment->id,
            ]);
        }
    }
}
