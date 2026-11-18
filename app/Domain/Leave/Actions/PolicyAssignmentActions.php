<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\CreditScheduleKind;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\EntitlementBasis;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeavePolicyRow;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\AllocationGenerator;
use App\Domain\Leave\Services\EntitlementCalculator;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Dating\EffectiveDatedSeries;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Support\DomainError;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penetapan Leave Policy (spec-02 §2.4): draft → submitted → cancelled.
 * Submit closes the previous assignment (A9), generates allocations for the
 * period containing effective_from and every later existing period, and posts
 * `policy_change` differences when the policy changes mid-period (§3.6).
 */
final class PolicyAssignmentActions
{
    public function __construct(
        private readonly EffectiveDatedSeries $series,
        private readonly AllocationGenerator $generator,
        private readonly EntitlementCalculator $calculator,
        private readonly LeaveLedger $ledger,
    ) {}

    /**
     * @param  array{employee_id: int, leave_policy_id: int, effective_from: string, remarks?: string|null}  $data
     */
    public function saveDraft(?LeavePolicyAssignment $assignment, array $data): LeavePolicyAssignment
    {
        $errors = [];
        if (! Employee::query()->whereKey($data['employee_id'])->exists()) {
            $errors['employee_id'] = __('leave.errors.invalid_employee');
        }
        if (! LeavePolicy::query()->whereKey($data['leave_policy_id'])->where('is_active', true)->exists()) {
            $errors['leave_policy_id'] = __('leave.errors.invalid_policy');
        }
        DomainValidation::throwIf($errors);

        if ($assignment === null) {
            return LeavePolicyAssignment::query()->create($data);
        }
        $assignment->update($data);

        return $assignment;
    }

    /**
     * @return list<string> warnings (translated)
     */
    public function submit(LeavePolicyAssignment $assignment, ?User $actor): array
    {
        return DB::transaction(function () use ($assignment, $actor): array {
            $previous = LeavePolicyAssignment::query()->participating()
                ->where('employee_id', $assignment->employee_id)
                ->whereDate('effective_from', '<', $assignment->effective_from->toDateString())
                ->orderByDesc('effective_from')->first();

            $assignment->transitionTo(DocumentStatus::Submitted, $actor);
            $this->series->insert($assignment);
            $assignment->refresh();

            $warnings = [];
            $periods = LeavePeriod::query()->whereDate('to_date', '>=', $assignment->effective_from->toDateString())
                ->when($assignment->effective_to !== null, fn ($q) => $q->whereDate('from_date', '<=', $assignment->effective_to->toDateString()))
                ->orderBy('from_date')->get();

            foreach ($periods as $period) {
                $midPeriod = $previous !== null && $period->from_date->lessThan($assignment->effective_from);
                if ($midPeriod) {
                    $warnings = array_merge($warnings, $this->applyPolicyChange($assignment, $previous, $period));
                }
                $this->generator->generate($assignment, $period);
            }

            return $warnings;
        });
    }

    /** Cancel: only when none of its allocations was debited; reverses its credits and re-opens the predecessor (A9). */
    public function cancel(LeavePolicyAssignment $assignment, User $actor, string $reason): LeavePolicyAssignment
    {
        return DB::transaction(function () use ($assignment, $actor, $reason): LeavePolicyAssignment {
            $wasSubmitted = $assignment->status === DocumentStatus::Submitted;
            $allocations = LeaveAllocation::query()->live()->where('leave_policy_assignment_id', $assignment->id)->get();

            foreach ($allocations as $allocation) {
                if ($this->hasDebits($allocation)) {
                    throw new LeaveRuleViolation('leave.errors.assignment_has_debits', ['number' => $allocation->number]);
                }
            }

            $assignment->transitionTo(DocumentStatus::Cancelled, $actor, $reason);
            $today = LeaveLedger::today();

            foreach ($allocations as $allocation) {
                $this->ledger->reverseAll(LeaveMovement::query()->where('leave_allocation_id', $allocation->id), $today, $assignment, $reason);
                LeaveCreditSchedule::query()->where('leave_allocation_id', $allocation->id)
                    ->where('status', CreditScheduleStatus::Pending->value)
                    ->update(['status' => CreditScheduleStatus::Skipped->value, 'failure_reason' => 'assignment_cancelled']);
                $allocation->transitionTo(DocumentStatus::Cancelled, $actor, $reason);
            }

            $this->ledger->reverseAll(LeaveMovement::query()->fromSource($assignment)->where('kind', MovementKind::PolicyChange->value), $today, $assignment, $reason);

            if ($wasSubmitted) {
                $this->series->remove($assignment);
            }

            return $assignment;
        });
    }

    /**
     * Penetapan Pukal: one submitted assignment per matching employee.
     *
     * @param  array{branch_id?: int|null, department_id?: int|null, employment_type_id?: int|null, employee_ids?: list<int>|null}  $filters
     * @return list<array{employee_id: int, employee: string, ok: bool, message: string|null}>
     */
    public function bulkAssign(LeavePolicy $policy, string $effectiveFrom, array $filters, User $actor, bool $preview = false): array
    {
        $employees = Employee::query()->where('status', '!=', EmployeeStatus::Left->value)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['employment_type_id'] ?? null, fn ($q, $v) => $q->where('employment_type_id', $v))
            ->when($filters['employee_ids'] ?? null, fn ($q, $v) => $q->whereIn('id', $v))
            ->orderBy('full_name')->get();

        $results = [];
        foreach ($employees as $employee) {
            if ($preview) {
                $results[] = ['employee_id' => $employee->id, 'employee' => $employee->full_name, 'ok' => true, 'message' => null];

                continue;
            }

            try {
                DB::transaction(function () use ($employee, $policy, $effectiveFrom, $actor): void {
                    $draft = $this->saveDraft(null, ['employee_id' => $employee->id, 'leave_policy_id' => $policy->id, 'effective_from' => $effectiveFrom]);
                    $this->submit($draft, $actor);
                });
                $results[] = ['employee_id' => $employee->id, 'employee' => $employee->full_name, 'ok' => true, 'message' => null];
            } catch (ValidationException $e) {
                $results[] = ['employee_id' => $employee->id, 'employee' => $employee->full_name, 'ok' => false, 'message' => implode(' ', array_merge(...array_values($e->errors())))];
            } catch (DomainError $e) {
                $results[] = ['employee_id' => $employee->id, 'employee' => $employee->full_name, 'ok' => false, 'message' => $e->userMessage()];
            }
        }

        return $results;
    }

    /**
     * Mid-period policy change (§3.6): recompute E across both regimes and post the
     * difference on existing allocations. A reduction never goes below what is left;
     * the excess is returned as a warning for HR.
     *
     * @return list<string>
     */
    public function applyPolicyChange(LeavePolicyAssignment $assignment, LeavePolicyAssignment $previous, LeavePeriod $period): array
    {
        $employee = Employee::query()->findOrFail($assignment->employee_id);
        $oldRows = LeavePolicyRow::query()->where('leave_policy_id', $previous->leave_policy_id)->with(['tiers', 'leaveType'])->get()->keyBy('leave_type_id');
        $newRows = LeavePolicyRow::query()->where('leave_policy_id', $assignment->leave_policy_id)->with(['tiers', 'leaveType'])->get()->keyBy('leave_type_id');
        $today = LeaveLedger::today();
        $warnings = [];
        $change = $assignment->effective_from;

        foreach ($oldRows->keys()->merge($newRows->keys())->unique() as $typeId) {
            $type = LeaveType::query()->find($typeId);
            if ($type === null || $type->entitlement_basis !== EntitlementBasis::PerPeriod) {
                continue; // earned types follow their own accrual schedule
            }
            $allocation = $this->generator->liveAllocation($employee->id, (int) $typeId, $period->id);
            if ($allocation === null) {
                continue; // a type new in this policy is generated from the change date
            }

            $windowTo = $period->to_date->min($assignment->effective_to ?? $period->to_date);
            $pieces = [
                ['from' => $allocation->from_date, 'to' => $change->subDay(), 'row' => $oldRows->get($typeId)],
                ['from' => $change, 'to' => $windowTo, 'row' => $newRows->get($typeId)],
            ];
            if ($allocation->from_date->greaterThanOrEqualTo($change)) {
                continue;
            }

            $ent = $this->calculator->calculate($employee, $type, $period, $pieces);
            $diff = Dec::sub($ent->total, $this->ledger->creditedEntitlement($allocation));

            LeaveCreditSchedule::query()->where('leave_allocation_id', $allocation->id)
                ->where('kind', CreditScheduleKind::TierChange->value)
                ->where('status', CreditScheduleStatus::Pending->value)
                ->update(['status' => CreditScheduleStatus::Skipped->value, 'failure_reason' => 'policy_changed']);

            if (Dec::isPositive($diff)) {
                $this->ledger->credit($allocation, MovementKind::PolicyChange, $diff, $change, $allocation->to_date, $today, $assignment);
            } elseif (Dec::isNegative($diff)) {
                $lot = $this->ledger->mainLot($allocation);
                $room = $lot === null ? '0' : Dec::max($this->ledger->remaining($lot), '0');
                $reduce = Dec::min(Dec::neg($diff), $room);
                if ($lot !== null && Dec::isPositive($reduce)) {
                    $this->ledger->debit($lot, MovementKind::PolicyChange, $reduce, $change, $change, $today, $assignment);
                }
                if (Dec::cmp($reduce, Dec::neg($diff)) < 0) {
                    $warnings[] = (string) __('leave.warnings.policy_change_excess', [
                        'type' => $type->displayName(), 'days' => Dec::short(Dec::sub(Dec::neg($diff), $reduce)),
                    ]);
                }
            }

            $allocation->forceFill(['entitlement_days' => $ent->total])->save();
        }

        return $warnings;
    }

    private function hasDebits(LeaveAllocation $allocation): bool
    {
        return LeaveMovement::query()->where('leave_allocation_id', $allocation->id)
            ->whereNotNull('lot_id')
            ->whereNotIn('kind', [MovementKind::Expiry->value, MovementKind::Reversal->value, MovementKind::PolicyChange->value])
            ->exists();
    }
}
