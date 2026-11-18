<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Core\Services\EmployeeValidator;
use App\Domain\Core\Services\WorkHistoryRecorder;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * "Pembetulan" (B3.6): HR Manager corrects the current assignment values and the
 * open work-history row in place, with a mandatory reason (logged).
 */
final class CorrectEmployeeAssignment
{
    public function __construct(
        private readonly EmployeeValidator $validator,
        private readonly WorkHistoryRecorder $history,
        private readonly UpdateEmployee $update,
    ) {}

    /**
     * @param  array<string, mixed>  $data  assignment fields + reason
     */
    public function handle(Employee $employee, array $data, User $actor): void
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw DomainValidation::withMessages(['reason' => __('validation.required', ['attribute' => __('validation.attributes.reason')])]);
        }

        $changes = Arr::only($data, Employee::ASSIGNMENT_FIELDS);
        $merged = array_merge($this->update->currentValues($employee), $changes);
        $this->validator->validate($merged, $employee);

        DB::transaction(function () use ($employee, $changes, $actor, $reason): void {
            $before = Arr::only($employee->getAttributes(), Employee::ASSIGNMENT_FIELDS);
            $employee->fill(array_map(fn ($v) => $v === '' ? null : $v, $changes));
            $employee->save();
            $this->history->correctCurrent($employee);

            activity('core')
                ->performedOn($employee)
                ->causedBy($actor)
                ->event('correction')
                ->withProperties(['reason' => $reason, 'before' => $before, 'after' => Arr::only($employee->getAttributes(), Employee::ASSIGNMENT_FIELDS)])
                ->log('correction');
        });
    }
}
