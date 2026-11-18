<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Exceptions\AttendanceRuleViolation;
use App\Domain\Attendance\Models\ShiftRequest;
use App\Domain\Attendance\Models\ShiftType;
use App\Domain\Attendance\Services\ShiftRequestApproval;
use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalService;
use App\Domain\Shared\Contracts\PayrollLockQuery;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Support\DomainValidation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Permohonan Shift around the approval engine (spec-03 §4.8): draft, submit,
 * withdraw, cancel after approval (only while no punches were processed against
 * the new assignment; HR outside locked periods, applicant only before it starts).
 */
final class ShiftRequestActions
{
    public const MAX_SPAN_DAYS = 31;

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ShiftRequestApproval $hooks,
        private readonly PayrollLockQuery $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(?ShiftRequest $request, Employee $employee, array $data): ShiftRequest
    {
        $request ??= new ShiftRequest(['employee_id' => $employee->id, 'organisation_id' => $employee->organisation_id]);
        if (! $request->isDraft()) {
            throw new AttendanceRuleViolation('attendance.errors.not_draft');
        }
        $request->fill([
            'shift_type_id' => $data['shift_type_id'],
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'] ?? $data['from_date'],
            'reason' => $data['reason'] ?? null,
        ]);
        $this->validate($request, $employee);
        $request->save();

        return $request;
    }

    /**
     * @param  array<string, mixed>  $choices
     */
    public function submit(ShiftRequest $request, User $actor, array $choices = []): ShiftRequest
    {
        return DB::transaction(function () use ($request, $actor, $choices): ShiftRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($request->employee_id);
            $this->validate($request, $employee);
            $this->approvals->submit($request, $actor, $choices);

            return $request->refresh();
        });
    }

    public function withdraw(ShiftRequest $request, User $actor, string $reason): void
    {
        if ($request->status !== ApprovalStatus::Pending) {
            throw new AttendanceRuleViolation('attendance.errors.not_pending');
        }
        $this->approvals->cancel($request->approvalRequest()->firstOrFail(), $actor, $reason);
    }

    public function cancelApproved(ShiftRequest $request, User $actor, string $reason, bool $asHr): void
    {
        if ($request->status !== ApprovalStatus::Approved) {
            throw new AttendanceRuleViolation('attendance.errors.not_approved');
        }
        if ($this->hooks->hasProcessedPunches($request)) {
            throw new AttendanceRuleViolation('attendance.errors.shift_request_used');
        }
        if ($asHr) {
            if ($this->locks->lockedDatesIn($request->approvalApplicant(), $request->range()) !== []) {
                throw new AttendanceRuleViolation('attendance.errors.period_locked', ['dates' => $request->from_date->format('d/m/Y')]);
            }
        } elseif (! $request->from_date->greaterThan(CarbonImmutable::today())) {
            throw new AttendanceRuleViolation('attendance.errors.cancel_started');
        }

        $this->approvals->cancel($request->approvalRequest()->firstOrFail(), $actor, $reason);
    }

    private function validate(ShiftRequest $r, Employee $employee): void
    {
        $errors = [];
        if ($r->to_date->lessThan($r->from_date)) {
            $errors['to_date'] = __('attendance.errors.range_invalid');
        } elseif ((int) $r->from_date->diffInDays($r->to_date) + 1 > self::MAX_SPAN_DAYS) {
            $errors['to_date'] = __('attendance.errors.range_too_long', ['days' => self::MAX_SPAN_DAYS]);
        }
        if (! ShiftType::query()->whereKey($r->shift_type_id)->where('is_active', true)->exists()) {
            $errors['shift_type_id'] = __('attendance.errors.shift_inactive');
        }
        if ($r->from_date->lessThan($employee->date_of_joining) || ($employee->relieving_date !== null && $r->to_date->greaterThan($employee->relieving_date))) {
            $errors['from_date'] = __('attendance.errors.outside_employment');
        }
        if ($errors === []) {
            $overlap = ShiftRequest::query()->where('employee_id', $employee->id)
                ->when($r->exists, fn ($q) => $q->whereKeyNot($r->id))
                ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value])
                ->whereDate('from_date', '<=', $r->to_date->toDateString())->whereDate('to_date', '>=', $r->from_date->toDateString())
                ->value('number');
            if ($overlap !== null) {
                $errors['from_date'] = __('attendance.errors.request_overlap', ['number' => $overlap]);
            }
        }
        DomainValidation::throwIf($errors);
    }
}
