<?php

declare(strict_types=1);

namespace App\Domain\Core\Actions;

use App\Domain\Core\Enums\AssignableType;
use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Events\HolidayAssignmentChanged;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\HolidayList;
use App\Domain\Core\Models\HolidayListAssignment;
use App\Domain\Shared\Dating\EffectiveDatedSeries;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Penetapan Senarai Cuti (HLA) lifecycle (B3.8): draft → submitted → cancelled,
 * effective-dated per assignable; "Batal & Salin" and bulk assignment.
 */
final class HolidayAssignmentActions
{
    public function __construct(private readonly EffectiveDatedSeries $series) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(?HolidayListAssignment $assignment, array $data): HolidayListAssignment
    {
        $this->validate($data);

        if ($assignment === null) {
            return HolidayListAssignment::query()->create($data);
        }

        $assignment->update($data);

        return $assignment;
    }

    public function submit(HolidayListAssignment $assignment, User $actor): HolidayListAssignment
    {
        return DB::transaction(function () use ($assignment, $actor): HolidayListAssignment {
            $this->validate($assignment->only(['holiday_list_id', 'assignable_type', 'assignable_id', 'effective_from']));

            $assignment->transitionTo(DocumentStatus::Submitted, $actor);
            $this->series->insert($assignment);

            HolidayAssignmentChanged::dispatch($assignment);

            return $assignment;
        });
    }

    public function cancel(HolidayListAssignment $assignment, User $actor, string $reason): HolidayListAssignment
    {
        return DB::transaction(function () use ($assignment, $actor, $reason): HolidayListAssignment {
            $wasSubmitted = $assignment->status === DocumentStatus::Submitted;
            $assignment->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

            if ($wasSubmitted) {
                $this->series->remove($assignment);
            }

            HolidayAssignmentChanged::dispatch($assignment);

            return $assignment;
        });
    }

    /** "Batal & Salin": cancel with reason, then open a prefilled draft pointing back. */
    public function cancelAndDuplicate(HolidayListAssignment $assignment, User $actor, string $reason): HolidayListAssignment
    {
        return DB::transaction(function () use ($assignment, $actor, $reason): HolidayListAssignment {
            $this->cancel($assignment, $actor, $reason);

            $copy = $assignment->duplicateAsDraft();
            $copy->setAttribute('effective_to', null);
            $copy->save();

            return $copy;
        });
    }

    /**
     * Bulk assign (B3.8): one employee-level draft per matching employee, submitted together.
     *
     * @param  array{branch_id?: int|null, department_id?: int|null, employment_type_id?: int|null}  $filters
     */
    public function bulkAssign(HolidayList $list, string $effectiveFrom, array $filters, User $actor, ?string $remarks = null): int
    {
        $employees = Employee::query()
            ->where('status', '!=', EmployeeStatus::Left->value)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['employment_type_id'] ?? null, fn ($q, $v) => $q->where('employment_type_id', $v))
            ->pluck('id');

        return DB::transaction(function () use ($employees, $list, $effectiveFrom, $actor, $remarks): int {
            $count = 0;
            foreach ($employees as $employeeId) {
                $exists = HolidayListAssignment::query()
                    ->where('assignable_type', AssignableType::Employee->value)
                    ->where('assignable_id', $employeeId)
                    ->where('status', DocumentStatus::Submitted->value)
                    ->whereDate('effective_from', $effectiveFrom)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $draft = $this->saveDraft(null, [
                    'holiday_list_id' => $list->id,
                    'assignable_type' => AssignableType::Employee->value,
                    'assignable_id' => (int) $employeeId,
                    'effective_from' => $effectiveFrom,
                    'remarks' => $remarks,
                ]);
                $this->submit($draft, $actor);
                $count++;
            }

            return $count;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data): void
    {
        $errors = [];
        $list = HolidayList::query()->find($data['holiday_list_id'] ?? null);
        $type = $data['assignable_type'] instanceof AssignableType
            ? $data['assignable_type']
            : AssignableType::tryFrom((string) ($data['assignable_type'] ?? ''));
        $from = empty($data['effective_from']) ? null : CarbonImmutable::parse((string) $data['effective_from']);

        if ($list === null) {
            $errors['holiday_list_id'] = __('validation.exists', ['attribute' => __('validation.attributes.holiday_list_id')]);
        } elseif ($from !== null && ! $list->covers($from)) {
            $errors['effective_from'] = __('core.holiday_assignment.outside_list_range');
        }

        if ($from === null) {
            $errors['effective_from'] = __('validation.required', ['attribute' => __('validation.attributes.effective_from')]);
        }

        $assignableId = (int) ($data['assignable_id'] ?? 0);
        $exists = match ($type) {
            AssignableType::Organisation => $assignableId === OrganisationContext::id(),
            AssignableType::Branch => Branch::query()->whereKey($assignableId)->exists(),
            AssignableType::Employee => Employee::query()->whereKey($assignableId)->exists(),
            null => false,
        };
        if (! $exists) {
            $errors['assignable_id'] = __('core.holiday_assignment.invalid_assignable');
        }

        DomainValidation::throwIf($errors);
    }
}
