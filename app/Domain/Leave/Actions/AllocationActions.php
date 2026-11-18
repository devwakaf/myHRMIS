<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Core\Models\Employee;
use App\Domain\Leave\Enums\AllocationSource;
use App\Domain\Leave\Enums\CreditScheduleStatus;
use App\Domain\Leave\Enums\MovementKind;
use App\Domain\Leave\Enums\PostedVia;
use App\Domain\Leave\Exceptions\LeaveRuleViolation;
use App\Domain\Leave\Models\LeaveAllocation;
use App\Domain\Leave\Models\LeaveCreditSchedule;
use App\Domain\Leave\Models\LeaveMovement;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\AllocationGenerator;
use App\Domain\Leave\Services\CarryForwardProcessor;
use App\Domain\Leave\Services\CreditPoster;
use App\Domain\Leave\Services\LeaveLedger;
use App\Domain\Leave\Support\Dec;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Peruntukan Cuti (spec-02 §2.5): manual allocations (draft → submitted →
 * cancelled), "Jana Peruntukan" for a period, credit retries and
 * "Kira Semula Bawa Ke Hadapan".
 */
final class AllocationActions
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly AllocationGenerator $generator,
        private readonly CreditPoster $poster,
        private readonly CarryForwardProcessor $carry,
    ) {}

    /**
     * @param  array{employee_id: int, leave_type_id: int, leave_period_id: int, from_date?: string|null, to_date?: string|null, initial_days: string|int, remarks?: string|null}  $data
     */
    public function saveManualDraft(?LeaveAllocation $allocation, array $data): LeaveAllocation
    {
        $period = LeavePeriod::query()->find($data['leave_period_id']);
        $type = LeaveType::query()->find($data['leave_type_id']);
        $employee = Employee::query()->find($data['employee_id']);
        $errors = [];
        if ($period === null) {
            $errors['leave_period_id'] = __('leave.errors.invalid_period');
        }
        if ($type === null || ! $type->hasBalance()) {
            $errors['leave_type_id'] = __('leave.errors.type_without_balance');
        }
        if ($employee === null) {
            $errors['employee_id'] = __('leave.errors.invalid_employee');
        }
        if (! is_numeric($data['initial_days']) || Dec::isNegative((string) $data['initial_days'])) {
            $errors['initial_days'] = __('validation.min.numeric', ['attribute' => __('leave.fields.days'), 'min' => 0]);
        }
        DomainValidation::throwIf($errors);
        /** @var LeavePeriod $period */
        /** @var Employee $employee */
        $from = isset($data['from_date']) && $data['from_date'] ? CarbonImmutable::parse($data['from_date']) : $period->from_date->max($employee->date_of_joining);
        $to = isset($data['to_date']) && $data['to_date'] ? CarbonImmutable::parse($data['to_date']) : $period->to_date;
        if ($from->lessThan($period->from_date) || $to->greaterThan($period->to_date) || $to->lessThan($from)) {
            throw DomainValidation::withMessages(['from_date' => __('leave.errors.allocation_outside_period')]);
        }

        $values = [
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'leave_period_id' => $period->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'source' => AllocationSource::Manual,
            'initial_days' => Dec::norm((string) $data['initial_days']),
            'entitlement_days' => Dec::norm((string) $data['initial_days']),
            'remarks' => $data['remarks'] ?? null,
        ];

        if ($allocation === null) {
            return LeaveAllocation::query()->create($values);
        }
        $allocation->update($values);

        return $allocation;
    }

    public function submitManual(LeaveAllocation $allocation, User $actor): LeaveAllocation
    {
        return DB::transaction(function () use ($allocation, $actor): LeaveAllocation {
            Employee::query()->whereKey($allocation->employee_id)->lockForUpdate()->value('id');
            if ($this->generator->liveAllocation($allocation->employee_id, $allocation->leave_type_id, $allocation->leave_period_id) !== null) {
                throw new LeaveRuleViolation('leave.errors.allocation_exists');
            }

            $allocation->transitionTo(DocumentStatus::Submitted, $actor);
            $this->ledger->credit($allocation, MovementKind::Allocation, (string) $allocation->initial_days, $allocation->from_date, $allocation->to_date, LeaveLedger::today(), $allocation);

            if ($allocation->from_date->lessThanOrEqualTo(LeaveLedger::today())) {
                $this->carry->process($allocation);
            }

            return $allocation->refresh();
        });
    }

    /** Blocked while any debit other than expiry points at its lots; credits and expiries are reversed. */
    public function cancel(LeaveAllocation $allocation, User $actor, string $reason): LeaveAllocation
    {
        return DB::transaction(function () use ($allocation, $actor, $reason): LeaveAllocation {
            $wasSubmitted = $allocation->status === DocumentStatus::Submitted;
            if ($wasSubmitted) {
                $debits = $this->ledger->unreversed(LeaveMovement::query()->where('leave_allocation_id', $allocation->id)
                    ->whereNotNull('lot_id')->where('kind', '!=', MovementKind::Expiry->value))->exists();
                if ($debits) {
                    throw new LeaveRuleViolation('leave.errors.allocation_has_debits');
                }
            }

            $allocation->transitionTo(DocumentStatus::Cancelled, $actor, $reason);

            if ($wasSubmitted) {
                $this->ledger->reverseAll(LeaveMovement::query()->where('leave_allocation_id', $allocation->id), LeaveLedger::today(), $allocation, $reason);
                LeaveCreditSchedule::query()->where('leave_allocation_id', $allocation->id)
                    ->where('status', CreditScheduleStatus::Pending->value)
                    ->update(['status' => CreditScheduleStatus::Skipped->value, 'failure_reason' => 'allocation_cancelled']);
            }

            return $allocation;
        });
    }

    /**
     * "Jana Peruntukan" (spec-02 §6.1/§6.3): allocations for every employee with a
     * submitted assignment overlapping the period. Idempotent.
     */
    public function generateForPeriod(LeavePeriod $period): int
    {
        $count = 0;
        $assignments = LeavePolicyAssignment::query()->participating()
            ->whereDate('effective_from', '<=', $period->to_date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->from_date->toDateString()))
            ->orderBy('employee_id')->orderBy('effective_from')
            ->get();

        foreach ($assignments as $assignment) {
            $count += count($this->generator->generate($assignment, $period));
        }

        DB::table('leave_periods')->where('id', $period->id)->update(['allocations_generated_at' => now()]);

        return $count;
    }

    /** HR retry of a failed (or pending) credit (spec-02 §2.7). */
    public function retryCredit(LeaveCreditSchedule $schedule): CreditScheduleStatus
    {
        if ($schedule->status === CreditScheduleStatus::Failed) {
            $schedule->forceFill(['status' => CreditScheduleStatus::Pending])->save();
        }

        return $this->poster->post($schedule, PostedVia::ManualRetry);
    }

    public function recomputeCarryForward(LeaveAllocation $allocation): string
    {
        return $this->carry->recompute($allocation);
    }
}
