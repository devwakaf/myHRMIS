<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Enums\MovementType;
use App\Domain\Core\Enums\SeparationReason;
use App\Domain\Core\Enums\WorkHistorySource;
use App\Domain\Core\Events\EmployeeMoved;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Department;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeMovement;
use App\Domain\Core\Models\EmployeeWorkHistory;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Services\EmployeeValidator;
use App\Domain\Core\Services\WorkHistoryRecorder;
use App\Domain\Shared\Authorization\PermissionMatrix;
use App\Domain\Shared\Authorization\RoleService;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pergerakan Kakitangan (B3.9): draft → submitted (applied now or by the daily job)
 * → cancelled (only the most recent applied movement; restores before_values).
 */
final class MovementActions
{
    public function __construct(
        private readonly EmployeeValidator $validator,
        private readonly WorkHistoryRecorder $history,
        private readonly UpdateEmployee $update,
        private readonly RoleService $roles,
        private readonly CreateEmployee $createEmployee,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{movement: EmployeeMovement, warnings: list<string>}
     */
    public function saveDraft(?EmployeeMovement $movement, array $data): array
    {
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $employee = Employee::query()->findOrFail($movement->employee_id ?? $data['employee_id'] ?? 0);
        $warnings = $this->validateMovement($employee, $data);

        if ($movement === null) {
            $movement = EmployeeMovement::query()->create($data);
        } else {
            $movement->update(Arr::except($data, ['employee_id']));
        }

        return ['movement' => $movement, 'warnings' => $warnings];
    }

    public function submit(EmployeeMovement $movement, User $actor): EmployeeMovement
    {
        return DB::transaction(function () use ($movement, $actor): EmployeeMovement {
            $employee = $movement->employee;
            $this->validateMovement($employee, $movement->only(array_merge(
                ['type', 'effective_date', 'target_organisation_id'],
                array_keys(EmployeeMovement::FIELD_MAP),
            )));

            if ($movement->type === MovementType::InterOrgTransfer) {
                $this->guardTargetRights($movement, $actor);
            }

            $clash = EmployeeMovement::query()
                ->where('employee_id', $movement->employee_id)
                ->where('status', DocumentStatus::Submitted->value)
                ->whereNull('applied_at')
                ->whereDate('effective_date', $movement->effective_date->toDateString())
                ->whereKeyNot($movement->id)
                ->exists();
            if ($clash) {
                throw DomainValidation::withMessages(['effective_date' => __('core.movement.duplicate_date')]);
            }

            $movement->transitionTo(DocumentStatus::Submitted, $actor);

            if ($movement->effective_date->lessThanOrEqualTo(CarbonImmutable::today())) {
                $this->apply($movement, $actor);
            }

            return $movement->refresh();
        });
    }

    /** Apply a submitted movement (B3.9): capture before/after, update employee, roll work history. */
    public function apply(EmployeeMovement $movement, ?User $actor = null): void
    {
        if ($movement->status !== DocumentStatus::Submitted || $movement->isApplied()) {
            return;
        }

        DB::transaction(function () use ($movement, $actor): void {
            $employee = Employee::query()->lockForUpdate()->findOrFail($movement->employee_id);

            if ($movement->type === MovementType::InterOrgTransfer) {
                $this->applyInterOrg($movement, $employee, $actor);

                return;
            }

            $changes = $this->changes($movement);
            $before = [];
            foreach (array_keys($changes) as $field) {
                $value = $employee->getAttribute($field);
                $before[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
            if ($movement->type === MovementType::Confirmation) {
                $before['confirmed_on'] = $employee->confirmed_on?->toDateString();
                $changes['confirmed_on'] = $movement->effective_date->toDateString();
            }

            $employee->fill($changes);
            $employee->save();

            $assignmentChanged = array_intersect(array_keys($changes), Employee::ASSIGNMENT_FIELDS) !== [];
            if ($assignmentChanged) {
                $this->history->transition($employee, $movement->effective_date, WorkHistorySource::Movement, $movement->id);
            }

            $movement->forceFill([
                'before_values' => $before,
                'after_values' => $changes,
                'applied_at' => now(),
            ])->save();

            EmployeeMoved::dispatch($employee, $movement);
        });
    }

    public function cancel(EmployeeMovement $movement, User $actor, string $reason): EmployeeMovement
    {
        return DB::transaction(function () use ($movement, $actor, $reason): EmployeeMovement {
            if ($movement->isApplied()) {
                $later = EmployeeMovement::query()
                    ->where('employee_id', $movement->employee_id)
                    ->where('status', DocumentStatus::Submitted->value)
                    ->whereNotNull('applied_at')
                    ->whereKeyNot($movement->id)
                    ->where(fn ($q) => $q->whereDate('effective_date', '>', $movement->effective_date->toDateString())
                        ->orWhere(fn ($w) => $w->whereDate('effective_date', $movement->effective_date->toDateString())
                            ->where('applied_at', '>', $movement->applied_at)))
                    ->exists();
                if ($later) {
                    throw DomainValidation::withMessages(['movement' => __('core.movement.cancel_later_first')]);
                }

                if ($movement->type === MovementType::InterOrgTransfer) {
                    throw DomainValidation::withMessages(['movement' => __('core.movement.inter_org_cancel_unsupported')]);
                }

                $this->reverse($movement);
            }

            $movement->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

            return $movement;
        });
    }

    /** "Batal & Salin". */
    public function cancelAndDuplicate(EmployeeMovement $movement, User $actor, string $reason): EmployeeMovement
    {
        return DB::transaction(function () use ($movement, $actor, $reason): EmployeeMovement {
            $this->cancel($movement, $actor, $reason);
            $copy = $movement->duplicateAsDraft();
            $copy->save();

            return $copy;
        });
    }

    private function reverse(EmployeeMovement $movement): void
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($movement->employee_id);
        $employee->fill($movement->before_values ?? []);
        $employee->save();

        $row = EmployeeWorkHistory::query()
            ->where('employee_id', $employee->id)
            ->where('source_type', WorkHistorySource::Movement->value)
            ->where('source_id', $movement->id)
            ->first();

        if ($row !== null) {
            $previous = EmployeeWorkHistory::query()
                ->where('employee_id', $employee->id)
                ->where('from_date', '<', $row->from_date->toDateString())
                ->orderByDesc('from_date')
                ->first();
            $rowTo = $row->to_date;
            $row->delete();
            if ($previous !== null) {
                $previous->update(['to_date' => $rowTo?->toDateString()]);
            } else {
                $this->history->open($employee, $employee->date_of_joining, WorkHistorySource::Correction);
            }
        }

        EmployeeMoved::dispatch($employee, $movement, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> warnings
     */
    private function validateMovement(Employee $employee, array $data): array
    {
        $type = $data['type'] instanceof MovementType ? $data['type'] : MovementType::tryFrom((string) ($data['type'] ?? ''));
        $errors = [];
        $warnings = [];

        if ($type === null) {
            throw DomainValidation::withMessages(['type' => __('validation.required', ['attribute' => __('validation.attributes.type')])]);
        }

        if ($employee->status === EmployeeStatus::Left
            || ($employee->status === EmployeeStatus::Suspended && $type === MovementType::Confirmation)) {
            throw DomainValidation::withMessages(['employee_id' => __('core.movement.employee_not_active')]);
        }

        if (empty($data['effective_date'])) {
            $errors['effective_date'] = __('validation.required', ['attribute' => __('validation.attributes.effective_date')]);
        }

        if ($type === MovementType::InterOrgTransfer) {
            if (empty($data['target_organisation_id']) || (int) $data['target_organisation_id'] === $employee->organisation_id) {
                $errors['target_organisation_id'] = __('core.movement.target_org_required');
            }
            DomainValidation::throwIf($errors);

            return $warnings;
        }

        $changes = [];
        foreach (EmployeeMovement::FIELD_MAP as $newField => $field) {
            $value = $data[$newField] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $current = $employee->getAttribute($field);
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
            $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            if ((string) $current !== (string) $value) {
                $changes[$field] = $value;
            }
        }

        if ($type !== MovementType::Confirmation && $changes === []) {
            $errors['movement'] = __('core.movement.nothing_changes');
        }

        if ($changes !== []) {
            $merged = array_merge($this->update->currentValues($employee), $changes);
            try {
                $this->validator->validate($merged, $employee);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $key = array_search($field, EmployeeMovement::FIELD_MAP, true);
                    $errors[$key === false ? $field : $key] = $messages[0];
                }
            }
        }

        if ($type === MovementType::Promotion && isset($changes['grade_id']) === false && isset($changes['designation_id']) === false) {
            $warnings[] = (string) __('core.movement.promotion_warning');
        }
        if ($type === MovementType::Promotion && isset($changes['grade_id'])) {
            $old = $employee->grade_id === null ? null : Grade::query()->find($employee->grade_id);
            $new = Grade::query()->find($changes['grade_id']);
            if ($old !== null && $new !== null && $new->rank <= $old->rank && ! isset($changes['designation_id'])) {
                $warnings[] = (string) __('core.movement.promotion_warning');
            }
        }

        DomainValidation::throwIf($errors);

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private function changes(EmployeeMovement $movement): array
    {
        $employee = $movement->employee;
        $changes = [];
        foreach (EmployeeMovement::FIELD_MAP as $newField => $field) {
            $value = $movement->getAttribute($newField);
            if ($value === null) {
                continue;
            }
            $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            $current = $employee->getAttribute($field);
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
            if ((string) $current !== (string) $value) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    private function guardTargetRights(EmployeeMovement $movement, User $actor): void
    {
        if (! $this->roles->can($actor, (int) $movement->target_organisation_id, 'core.movement.submit')) {
            throw DomainValidation::withMessages(['target_organisation_id' => __('core.movement.no_rights_in_target')]);
        }
    }

    /**
     * Inter-organisation transfer (B3.9): new record in the target org, user link
     * moved, old record separated the day before with reason transfer_out.
     */
    private function applyInterOrg(EmployeeMovement $movement, Employee $employee, ?User $actor): void
    {
        $target = Organisation::query()->findOrFail($movement->target_organisation_id);
        $effective = $movement->effective_date;
        $userId = $employee->user_id;

        $payload = $this->update->currentValues($employee);
        unset($payload['employee_no'], $payload['user_id'], $payload['branch_id'], $payload['department_id'],
            $payload['designation_id'], $payload['grade_id'], $payload['employment_type_id'], $payload['reports_to_id'],
            $payload['bank_id'], $payload['resignation_date'], $payload['relieving_date'], $payload['probation_end_date'],
            $payload['confirmed_on']);
        $tax = $employee->taxProfile?->attributesToArray() ?? [];
        $tax['zakat_monthly_amount'] = $employee->taxProfile?->zakat_monthly_amount?->toDecimalString();
        $contacts = $employee->emergencyContacts->map(fn ($c) => [
            'name' => $c->name, 'relationship' => $c->relationship->value, 'phone' => $c->phone,
            'alt_phone' => $c->alt_phone, 'address' => $c->address, 'is_primary' => $c->is_primary,
        ])->all();
        $continuous = $employee->serviceStart()->toDateString();
        $bankId = $employee->bank_id;

        $employee->forceFill(['user_id' => null])->save();

        $newEmployee = OrganisationContext::run($target, function () use ($payload, $tax, $contacts, $effective, $continuous, $bankId, $movement, $employee, $userId): Employee {
            $defaults = $this->targetDefaults();
            $result = $this->createEmployee->handle(array_merge($payload, $defaults, [
                'date_of_joining' => $effective->toDateString(),
                'continuous_service_from' => $continuous,
                'bank_id' => $bankId,
                'tax' => Arr::only($tax, CreateEmployee::taxFields()),
                'emergency_contacts' => $contacts,
            ], array_filter([
                'department_id' => $movement->new_department_id,
                'designation_id' => $movement->new_designation_id,
                'employment_type_id' => $movement->new_employment_type_id,
                'branch_id' => $movement->new_branch_id,
                'grade_id' => $movement->new_grade_id,
            ])), ['previous_employee_id' => $employee->id, 'user_id' => $userId]);

            return $result['employee'];
        });

        if ($userId !== null) {
            $user = User::query()->find($userId);
            if ($user !== null) {
                $this->roles->assign($user, $target->id, PermissionMatrix::KAKITANGAN);
            }
        }

        $employee->forceFill([
            'relieving_date' => $effective->subDay()->toDateString(),
            'separation_reason' => SeparationReason::TransferOut,
            'separation_note' => $movement->number,
        ])->save();

        $movement->forceFill([
            'before_values' => ['organisation_id' => $employee->organisation_id, 'employee_id' => $employee->id],
            'after_values' => ['organisation_id' => $target->id, 'employee_id' => $newEmployee->id],
            'applied_at' => now(),
            'target_employee_id' => $newEmployee->id,
        ])->save();

        if ($employee->relieving_date !== null && $employee->relieving_date->lessThan(CarbonImmutable::today())) {
            app(ProcessSeparation::class)->handle($employee->refresh());
        }

        EmployeeMoved::dispatch($employee, $movement);
    }

    /**
     * Fallback master ids in the target org when the movement did not choose them
     * (the root department, first designation and first permanent employment type).
     *
     * @return array<string, int|null>
     */
    private function targetDefaults(): array
    {
        return [
            'department_id' => Department::query()->whereNull('parent_id')->value('id'),
            'designation_id' => Designation::query()->where('is_active', true)->orderBy('id')->value('id'),
            'employment_type_id' => EmploymentType::query()->where('is_active', true)->orderBy('id')->value('id'),
            'branch_id' => Branch::query()->where('is_active', true)->orderBy('id')->value('id'),
            'grade_id' => null,
        ];
    }
}
